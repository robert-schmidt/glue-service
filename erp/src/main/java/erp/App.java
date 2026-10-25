package erp;

import java.io.IOException;
import java.math.BigDecimal;
import java.util.List;
import java.util.Map;

import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.context.annotation.Bean;
import org.springframework.http.HttpStatus;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.filter.OncePerRequestFilter;
import org.springframework.web.server.ResponseStatusException;

import static org.springframework.http.HttpStatus.*;

/**
 * "Legacy" ERP: system of record for customers, products and sales orders.
 * Typical of the breed: UPPER_CASE JSON, full-record PUTs, no change feed, no concurrency control.
 */
@SpringBootApplication
@RestController
public class App {
    public static void main(String[] args) { SpringApplication.run(App.class, args); }

    static final String CUSTOMER = "SELECT code CUST_NO, name CUST_NAME, email EMAIL, address ADDR_LINE1, city CITY, ver ROW_VER FROM customers";
    static final String PRODUCT = "SELECT sku SKU, descr DESCR, price PRICE, qty_on_hand QTY_ON_HAND, status STATUS, ver ROW_VER FROM products";
    static final String ORDER = "SELECT order_no ORDER_NO, cust_no CUST_NO, status STATUS, tracking_no TRACKING_NO, ver ROW_VER FROM orders";

    private final JdbcTemplate db;

    App(JdbcTemplate db) { this.db = db; }

    // ---- customers

    @GetMapping("/api/customers")
    List<Map<String, Object>> customers() { return db.queryForList(CUSTOMER + " ORDER BY code"); }

    @GetMapping("/api/customers/{code}")
    Map<String, Object> customer(@PathVariable String code) { return one(CUSTOMER + " WHERE code = ?", code); }

    /** Full-record replace, last write wins: the lost-update trap the glue protects against. */
    @PutMapping("/api/customers/{code}")
    Map<String, Object> putCustomer(@PathVariable String code, @RequestBody Map<String, Object> b) {
        var v = required(b, "CUST_NAME", "EMAIL", "ADDR_LINE1", "CITY");
        found(db.update("UPDATE customers SET name = ?, email = ?, address = ?, city = ?, ver = ver + 1 WHERE code = ?", v[0], v[1], v[2], v[3], code));
        return customer(code);
    }

    // ---- products

    @GetMapping("/api/products")
    List<Map<String, Object>> products() { return db.queryForList(PRODUCT + " ORDER BY sku"); }

    @GetMapping("/api/products/{sku}")
    Map<String, Object> product(@PathVariable String sku) { return one(PRODUCT + " WHERE sku = ?", sku); }

    @PutMapping("/api/products/{sku}")
    Map<String, Object> putProduct(@PathVariable String sku, @RequestBody Map<String, Object> b) {
        var v = required(b, "DESCR", "PRICE");
        found(db.update("UPDATE products SET descr = ?, price = ?, ver = ver + 1 WHERE sku = ?", v[0], new BigDecimal(v[1]), sku));
        return product(sku);
    }

    @PutMapping("/api/products/{sku}/stock")
    Map<String, Object> putStock(@PathVariable String sku, @RequestBody Map<String, Object> b) {
        int qty = intOf(required(b, "QTY")[0]);
        if (qty < 0) throw error(BAD_REQUEST, "QTY must be >= 0");
        if ("BLOCKED".equals(product(sku).get("STATUS"))) throw error(UNPROCESSABLE_ENTITY, sku + " is blocked for stock postings");
        db.update("UPDATE products SET qty_on_hand = ?, ver = ver + 1 WHERE sku = ?", qty, sku);
        return product(sku);
    }

    // ---- sales orders

    @GetMapping("/api/orders")
    List<Map<String, Object>> orders(@RequestParam(required = false) String status) {
        var rows = status == null
                ? db.queryForList(ORDER + " ORDER BY order_no")
                : db.queryForList(ORDER + " WHERE status = ? ORDER BY order_no", status);
        rows.forEach(this::withLines);
        return rows;
    }

    @GetMapping("/api/orders/{no}")
    Map<String, Object> order(@PathVariable String no) { return withLines(one(ORDER + " WHERE order_no = ?", no)); }

    @PostMapping("/api/orders")
    synchronized Map<String, Object> createOrder(@RequestBody Map<String, Object> b) {
        String cust = required(b, "CUST_NO")[0];
        customer(cust);
        if (!(b.get("LINES") instanceof List<?> lines) || lines.isEmpty()) throw error(BAD_REQUEST, "LINES is required");
        String no = db.queryForObject("SELECT CONCAT('SO-', COALESCE(MAX(CAST(SUBSTRING(order_no, 4) AS UNSIGNED)), 5000) + 1) FROM orders", String.class);
        db.update("INSERT INTO orders (order_no, cust_no) VALUES (?, ?)", no, cust);
        for (var l : lines) {
            var line = (Map<?, ?>) l;
            db.update("INSERT INTO order_lines (order_no, sku, qty) VALUES (?, ?, ?)", no, line.get("SKU"), intOf(String.valueOf(line.get("QTY"))));
        }
        return order(no);
    }

    @PostMapping("/api/orders/{no}/ship")
    Map<String, Object> ship(@PathVariable String no, @RequestBody Map<String, Object> b) {
        String tracking = required(b, "TRACKING_NO")[0];
        switch ((String) order(no).get("STATUS")) {
            case "SHIPPED" -> throw error(CONFLICT, no + " is already shipped");
            case "CANCELLED" -> throw error(UNPROCESSABLE_ENTITY, no + " is cancelled");
            default -> { }
        }
        db.update("UPDATE orders SET status = 'SHIPPED', tracking_no = ?, ver = ver + 1 WHERE order_no = ?", tracking, no);
        return order(no);
    }

    @PostMapping("/api/orders/{no}/cancel")
    Map<String, Object> cancel(@PathVariable String no) {
        if ("SHIPPED".equals(order(no).get("STATUS"))) throw error(CONFLICT, no + " is already shipped");
        db.update("UPDATE orders SET status = 'CANCELLED', ver = ver + 1 WHERE order_no = ?", no);
        return order(no);
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

    private Map<String, Object> withLines(Map<String, Object> order) {
        order.put("LINES", db.queryForList("SELECT sku SKU, qty QTY FROM order_lines WHERE order_no = ?", order.get("ORDER_NO")));
        return order;
    }

    private Map<String, Object> one(String sql, Object... args) {
        var rows = db.queryForList(sql, args);
        if (rows.isEmpty()) throw error(NOT_FOUND, "not found");
        return rows.get(0);
    }

    private static void found(int updated) { if (updated == 0) throw error(NOT_FOUND, "not found"); }

    private static String[] required(Map<String, Object> b, String... keys) {
        var out = new String[keys.length];
        for (int i = 0; i < keys.length; i++) {
            var v = b.get(keys[i]);
            if (v == null || v.toString().isBlank()) throw error(BAD_REQUEST, keys[i] + " is required");
            out[i] = v.toString().trim();
        }
        return out;
    }

    private static int intOf(String s) {
        try { return Integer.parseInt(s); } catch (NumberFormatException e) { throw error(BAD_REQUEST, "not an integer: " + s); }
    }

    private static ResponseStatusException error(HttpStatus status, String message) { return new ResponseStatusException(status, message); }
}
