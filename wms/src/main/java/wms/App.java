package wms;

import java.io.IOException;
import java.time.Duration;
import java.util.List;
import java.util.Map;

import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.context.annotation.Bean;
import org.springframework.core.ParameterizedTypeReference;
import org.springframework.http.HttpStatus;
import org.springframework.http.client.SimpleClientHttpRequestFactory;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.scheduling.annotation.EnableScheduling;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.transaction.support.TransactionTemplate;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.client.RestClient;
import org.springframework.web.client.RestClientException;
import org.springframework.web.client.RestClientResponseException;
import org.springframework.web.filter.OncePerRequestFilter;
import org.springframework.web.server.ResponseStatusException;

import static org.springframework.http.HttpStatus.*;
import static org.springframework.http.MediaType.APPLICATION_JSON;

/**
 * "Legacy" warehouse app. Pulls master data and open orders from the ERP, owns stock and shipments,
 * and writes back to the ERP synchronously. It knows nothing about the glue service.
 */
@SpringBootApplication
@EnableScheduling
@RestController
public class App {
    public static void main(String[] args) { SpringApplication.run(App.class, args); }

    static final Logger log = LoggerFactory.getLogger(App.class);
    static final ParameterizedTypeReference<List<Map<String, Object>>> LIST = new ParameterizedTypeReference<>() { };
    static final String ITEM = "SELECT sku, description, unit_price \"unitPrice\", on_hand \"onHand\", bin FROM items";
    static final String PARTNER = "SELECT erp_ref \"erpRef\", name, email, ship_address \"shipAddress\", ship_city \"shipCity\" FROM partners";

    private final JdbcTemplate db;
    private final TransactionTemplate tx;
    private final RestClient erp;

    App(JdbcTemplate db, TransactionTemplate tx, @Value("${erp.base-url}") String erpUrl) {
        this.db = db;
        this.tx = tx;
        var http = new SimpleClientHttpRequestFactory();
        http.setConnectTimeout(Duration.ofSeconds(2));
        http.setReadTimeout(Duration.ofSeconds(5));
        this.erp = RestClient.builder().baseUrl(erpUrl).requestFactory(http).build();
        log.info("ERP endpoint: {}", erpUrl);
    }

    // ---- the original integration: poll the ERP, copy rows whose ROW_VER went up

    @Scheduled(fixedDelayString = "${erp.pull-ms}", initialDelay = 3000)
    @SuppressWarnings("unchecked")
    void pullFromErp() {
        try {
            for (var p : get("/api/products"))
                db.update("""
                        INSERT INTO items (sku, description, unit_price, on_hand, erp_ver) VALUES (?, ?, ?, ?, ?)
                        ON CONFLICT (sku) DO UPDATE SET description = EXCLUDED.description, unit_price = EXCLUDED.unit_price, erp_ver = EXCLUDED.erp_ver
                        WHERE items.erp_ver < EXCLUDED.erp_ver""",
                        p.get("SKU"), p.get("DESCR"), p.get("PRICE"), p.get("QTY_ON_HAND"), p.get("ROW_VER"));
            for (var c : get("/api/customers"))
                db.update("""
                        INSERT INTO partners (erp_ref, name, email, ship_address, ship_city, erp_ver) VALUES (?, ?, ?, ?, ?, ?)
                        ON CONFLICT (erp_ref) DO UPDATE SET name = EXCLUDED.name, email = EXCLUDED.email,
                            ship_address = EXCLUDED.ship_address, ship_city = EXCLUDED.ship_city, erp_ver = EXCLUDED.erp_ver
                        WHERE partners.erp_ver < EXCLUDED.erp_ver""",
                        c.get("CUST_NO"), c.get("CUST_NAME"), c.get("EMAIL"), c.get("ADDR_LINE1"), c.get("CITY"), c.get("ROW_VER"));
            for (var o : get("/api/orders?status=OPEN"))
                if (db.update("INSERT INTO orders (order_no, erp_ref) VALUES (?, ?) ON CONFLICT DO NOTHING", o.get("ORDER_NO"), o.get("CUST_NO")) == 1)
                    for (var l : (List<Map<String, Object>>) o.get("LINES"))
                        db.update("INSERT INTO order_lines (order_no, sku, qty) VALUES (?, ?, ?)", o.get("ORDER_NO"), l.get("SKU"), l.get("QTY"));
        } catch (RestClientException e) {
            log.warn("ERP pull failed: {}", e.getMessage());
        }
    }

    // ---- items / stock (owned by the WMS)

    @GetMapping("/api/items")
    List<Map<String, Object>> items() { return db.queryForList(ITEM + " ORDER BY sku"); }

    @GetMapping("/api/items/{sku}")
    Map<String, Object> item(@PathVariable String sku) { return one(ITEM + " WHERE sku = ?", sku); }

    @PutMapping("/api/items/{sku}")
    Map<String, Object> putItem(@PathVariable String sku, @RequestBody Map<String, Object> b) {
        found(db.update("UPDATE items SET description = COALESCE(?::text, description), unit_price = COALESCE(?::numeric, unit_price) WHERE sku = ?",
                b.get("description"), b.get("unitPrice"), sku));
        return item(sku);
    }

    /** Stock count / adjustment on the floor. Posts the new level to the ERP; if that fails it only logs it (= silent drift). */
    @PostMapping("/api/items/{sku}/adjust")
    Map<String, Object> adjust(@PathVariable String sku, @RequestBody Map<String, Object> b) {
        if (!(b.get("delta") instanceof Number n)) throw error(BAD_REQUEST, "delta is required");
        int delta = n.intValue();
        var rows = db.queryForList("UPDATE items SET on_hand = on_hand + ? WHERE sku = ? AND on_hand + ? >= 0 RETURNING on_hand", delta, sku, delta);
        if (rows.isEmpty()) throw error(BAD_REQUEST, "unknown sku or stock would go negative");
        int onHand = ((Number) rows.get(0).get("on_hand")).intValue();
        return Map.of("sku", sku, "onHand", onHand, "erpPosted", postStock(sku, onHand));
    }

    // ---- partners (customer master copied from the ERP; warehouse staff fix delivery addresses)

    @GetMapping("/api/partners")
    List<Map<String, Object>> partners() { return db.queryForList(PARTNER + " ORDER BY erp_ref"); }

    @GetMapping("/api/partners/{ref}")
    Map<String, Object> partner(@PathVariable String ref) { return one(PARTNER + " WHERE erp_ref = ?", ref); }

    /** Saves locally, then sends the WHOLE record to the ERP (the ERP API only takes full records). */
    @PutMapping("/api/partners/{ref}")
    Map<String, Object> putPartner(@PathVariable String ref, @RequestBody Map<String, Object> b) {
        found(db.update("""
                UPDATE partners SET name = COALESCE(?::text, name), email = COALESCE(?::text, email),
                    ship_address = COALESCE(?::text, ship_address), ship_city = COALESCE(?::text, ship_city)
                WHERE erp_ref = ?""", b.get("name"), b.get("email"), b.get("shipAddress"), b.get("shipCity"), ref));
        var p = partner(ref);
        boolean posted = true;
        try {
            erp.put().uri("/api/customers/{ref}", ref).contentType(APPLICATION_JSON)
                    .body(Map.of("CUST_NAME", p.get("name"), "EMAIL", p.get("email"), "ADDR_LINE1", p.get("shipAddress"), "CITY", p.get("shipCity")))
                    .retrieve().toBodilessEntity();
        } catch (RestClientException e) {
            log.warn("partner {} not posted to ERP: {}", ref, e.getMessage());
            posted = false;
        }
        p.put("erpPosted", posted);
        return p;
    }

    // ---- orders & shipments

    @GetMapping("/api/orders")
    List<Map<String, Object>> orders() { return db.queryForList("SELECT order_no \"orderNo\", erp_ref \"custNo\", status FROM orders ORDER BY order_no"); }

    @GetMapping("/api/shipments")
    List<Map<String, Object>> shipments() {
        return db.queryForList("SELECT order_no \"orderNo\", tracking_no \"trackingNo\", shipped_at \"shippedAt\" FROM shipments ORDER BY shipped_at");
    }

    /** Ship an order: book it locally and confirm to the ERP in one transaction. ERP down = shipment refused, try again later. */
    @PostMapping("/api/shipments")
    Map<String, Object> ship(@RequestBody Map<String, Object> b) {
        if (!(b.get("orderNo") instanceof String no) || !(b.get("trackingNo") instanceof String tracking)) throw error(BAD_REQUEST, "orderNo and trackingNo are required");
        List<Map<String, Object>> stock = tx.execute(s -> {
            if (db.update("UPDATE orders SET status = 'SHIPPED' WHERE order_no = ? AND status = 'RECEIVED'", no) == 0)
                throw error(CONFLICT, no + " is unknown or already shipped");
            db.update("INSERT INTO shipments (order_no, tracking_no) VALUES (?, ?)", no, tracking);
            var levels = db.queryForList("UPDATE items i SET on_hand = i.on_hand - l.qty FROM order_lines l WHERE l.order_no = ? AND l.sku = i.sku RETURNING i.sku, i.on_hand", no);
            try {
                erp.post().uri("/api/orders/{no}/ship", no).contentType(APPLICATION_JSON).body(Map.of("TRACKING_NO", tracking)).retrieve().toBodilessEntity();
            } catch (RestClientResponseException e) {
                throw error(e.getStatusCode().is4xxClientError() ? CONFLICT : BAD_GATEWAY, "ERP refused the shipment: " + e.getResponseBodyAsString());
            } catch (RestClientException e) {
                throw error(BAD_GATEWAY, "ERP unavailable, shipment not booked - try again later");
            }
            return levels;
        });
        stock.forEach(l -> postStock((String) l.get("sku"), ((Number) l.get("on_hand")).intValue()));
        return Map.of("orderNo", no, "trackingNo", tracking, "status", "SHIPPED");
    }

    // ---- fault injection: random 503s and extra latency on /api/*

    static volatile double failRate = Double.parseDouble(System.getenv().getOrDefault("CHAOS_FAIL_RATE", "0"));
    static volatile long latencyMs = Long.parseLong(System.getenv().getOrDefault("CHAOS_LATENCY_MS", "0"));

    @Bean
    OncePerRequestFilter chaosFilter() {
        return new OncePerRequestFilter() {
            @Override
            protected void doFilterInternal(HttpServletRequest req, HttpServletResponse res, FilterChain chain) throws ServletException, IOException {
                if (req.getRequestURI().startsWith("/api/")) {
                    if (latencyMs > 0) try { Thread.sleep(latencyMs); } catch (InterruptedException ignored) { }
                    if (Math.random() < failRate) { res.sendError(503, "chaos: injected failure"); return; }
                }
                chain.doFilter(req, res);
            }
        };
    }

    @RequestMapping(value = "/admin/chaos", method = {RequestMethod.GET, RequestMethod.POST})
    Map<String, Object> chaosSettings(@RequestBody(required = false) Map<String, Number> b) {
        if (b != null && b.get("failRate") != null) failRate = b.get("failRate").doubleValue();
        if (b != null && b.get("latencyMs") != null) latencyMs = b.get("latencyMs").longValue();
        return Map.of("failRate", failRate, "latencyMs", latencyMs);
    }

    // ---- helpers

    private List<Map<String, Object>> get(String path) { return erp.get().uri(path).retrieve().body(LIST); }

    private boolean postStock(String sku, int qty) {
        try {
            erp.put().uri("/api/products/{sku}/stock", sku).contentType(APPLICATION_JSON).body(Map.of("QTY", qty)).retrieve().toBodilessEntity();
            return true;
        } catch (RestClientException e) {
            log.warn("stock for {} not posted to ERP: {}", sku, e.getMessage());
            return false;
        }
    }

    private Map<String, Object> one(String sql, Object... args) {
        var rows = db.queryForList(sql, args);
        if (rows.isEmpty()) throw error(NOT_FOUND, "not found");
        return rows.get(0);
    }

    private static void found(int updated) { if (updated == 0) throw error(NOT_FOUND, "not found"); }

    private static ResponseStatusException error(HttpStatus status, String message) { return new ResponseStatusException(status, message); }
}
