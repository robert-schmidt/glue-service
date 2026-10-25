package erp;

import java.util.Map;

import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.boot.test.web.client.TestRestTemplate;
import org.springframework.boot.testcontainers.service.connection.ServiceConnection;
import org.springframework.http.HttpEntity;
import org.springframework.http.HttpMethod;
import org.springframework.http.ResponseEntity;
import org.testcontainers.containers.MySQLContainer;
import org.testcontainers.junit.jupiter.Container;
import org.testcontainers.junit.jupiter.Testcontainers;
import org.testcontainers.utility.MountableFile;

import static org.assertj.core.api.Assertions.assertThat;

/**
 * The ERP behaviour the glue relies on, against a real MySQL with the shipped schema and seed (db/).
 * Each test uses its own records, so the order they run in does not matter.
 */
@Testcontainers
@SpringBootTest(webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT)
class ContractTest {

    @Container
    @ServiceConnection
    static MySQLContainer<?> mysql = new MySQLContainer<>("mysql:8.4")
            .withDatabaseName("erp").withUsername("erp").withPassword("erp")
            .withCopyFileToContainer(MountableFile.forHostPath("db/01-schema-and-seed.sql"), "/docker-entrypoint-initdb.d/01.sql");

    @Autowired
    TestRestTemplate http;

    @SuppressWarnings({"rawtypes", "unchecked"})
    ResponseEntity<Map<String, Object>> call(HttpMethod method, String path, Map<String, ?> body) {
        return (ResponseEntity) http.exchange(path, method, body == null ? null : new HttpEntity<>(body), Map.class);
    }

    Map<String, Object> get(String path) { return call(HttpMethod.GET, path, null).getBody(); }

    @Test
    void customerPutReplacesTheWholeRecordAndBumpsRowVer() {
        var before = get("/api/customers/C-1001");
        var r = call(HttpMethod.PUT, "/api/customers/C-1001",
                Map.of("CUST_NAME", "Baltic Tooling SRL", "EMAIL", "new@baltic.example", "ADDR_LINE1", "Dock 1", "CITY", "Cluj-Napoca"));
        assertThat(r.getStatusCode().value()).isEqualTo(200);
        assertThat(r.getBody()).containsEntry("EMAIL", "new@baltic.example").containsEntry("ADDR_LINE1", "Dock 1");
        assertThat((Integer) r.getBody().get("ROW_VER")).isEqualTo((Integer) before.get("ROW_VER") + 1);
    }

    @Test
    void customerPutWithoutAllFieldsIsRefused() {
        assertThat(call(HttpMethod.PUT, "/api/customers/C-1002", Map.of("EMAIL", "x@y.example")).getStatusCode().value()).isEqualTo(400);
        assertThat(call(HttpMethod.PUT, "/api/customers/C-9999",
                Map.of("CUST_NAME", "x", "EMAIL", "x", "ADDR_LINE1", "x", "CITY", "x")).getStatusCode().value()).isEqualTo(404);
    }

    @Test
    void stockPutIsAnAbsoluteLevelSoRepeatingItIsHarmless() {
        for (int i = 0; i < 2; i++) {
            var r = call(HttpMethod.PUT, "/api/products/SKU-1003/stock", Map.of("QTY", 77));
            assertThat(r.getStatusCode().value()).isEqualTo(200);
            assertThat(r.getBody()).containsEntry("QTY_ON_HAND", 77);
        }
        assertThat(call(HttpMethod.PUT, "/api/products/SKU-1003/stock", Map.of("QTY", -1)).getStatusCode().value()).isEqualTo(400);
    }

    @Test
    void stockForABlockedProductIsABusinessError() {
        var r = call(HttpMethod.PUT, "/api/products/SKU-1007/stock", Map.of("QTY", 5));
        assertThat(r.getStatusCode().value()).isEqualTo(422);
        assertThat(r.getBody().get("message").toString()).contains("blocked");
    }

    @Test
    void shippingTwiceGives409SoARetryIsRecognised() {
        var first = call(HttpMethod.POST, "/api/orders/SO-5003/ship", Map.of("TRACKING_NO", "TRK-1"));
        assertThat(first.getStatusCode().value()).isEqualTo(200);
        assertThat(first.getBody()).containsEntry("STATUS", "SHIPPED").containsEntry("TRACKING_NO", "TRK-1");
        var again = call(HttpMethod.POST, "/api/orders/SO-5003/ship", Map.of("TRACKING_NO", "TRK-1"));
        assertThat(again.getStatusCode().value()).isEqualTo(409);
        assertThat(get("/api/orders/SO-5003")).containsEntry("ROW_VER", 2); // applied once
    }

    @Test
    void shippingACancelledOrderIsABusinessError() {
        assertThat(call(HttpMethod.POST, "/api/orders/SO-5006/ship", Map.of("TRACKING_NO", "TRK-2")).getStatusCode().value()).isEqualTo(422);
        assertThat(call(HttpMethod.POST, "/api/orders/SO-5004/ship", Map.of()).getStatusCode().value()).isEqualTo(400);
    }

    @Test
    void chaosReturns503OnTheApiButNotOnAdmin() {
        call(HttpMethod.POST, "/admin/chaos", Map.of("failRate", 1.0, "latencyMs", 0));
        try {
            assertThat(call(HttpMethod.GET, "/api/products", null).getStatusCode().value()).isEqualTo(503);
            assertThat(call(HttpMethod.GET, "/admin/chaos", null).getStatusCode().value()).isEqualTo(200);
        } finally {
            call(HttpMethod.POST, "/admin/chaos", Map.of("failRate", 0.0, "latencyMs", 0));
        }
    }
}
