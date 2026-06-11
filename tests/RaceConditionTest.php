<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * RaceConditionTest
 *
 * Functional test that validates the API's ability to handle a flash-sale
 * race condition.
 *
 * Strategy
 * ────────
 * 1.  Create (or reset) a product with a limited inventory and flash sale ON.
 * 2.  Fire N concurrent HTTP requests (N > inventory) in parallel using
 *     curl_multi_exec, so they all hit the server at roughly the same time.
 * 3.  Assert that:
 *       a. Exactly `inventory` requests succeeded (HTTP 201).
 *       b. The remaining requests were rejected (HTTP 409 – out of stock).
 *       c. The product's final inventory is exactly 0 (never negative).
 *
 * How to run
 * ──────────
 *   # Start the built-in PHP server in one terminal:
 *   php -S localhost:8000 -t public/
 *
 *   # Run the test suite in another:
 *   ./vendor/bin/phpunit --testdox
 *
 *   # Override the API URL:
 *   API_BASE_URL=http://my-server ./vendor/bin/phpunit
 *
 * Requirements
 * ────────────
 *   - A running instance of the API (see above).
 *   - The database seeded with the migration (migrations/001_create_tables.sql).
 *   - The curl and json PHP extensions.
 */
class RaceConditionTest extends TestCase
{
    /** Base URL of the running API instance. */
    private string $baseUrl;

    /** Number of concurrent order requests to fire. */
    private const CONCURRENT_REQUESTS = 20;

    /** Inventory we will set on the test product (must be < CONCURRENT_REQUESTS). */
    private const FLASH_SALE_STOCK = 5;

    /** ID of the product we will use/create. */
    private int $productId;

    // ─────────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->baseUrl = rtrim(getenv('API_BASE_URL') ?: 'http://localhost:8000', '/');

        // Verify the API is reachable before running tests
        $ping = $this->httpGet('/health');
        if ($ping['status'] !== 200) {
            self::markTestSkipped(
                "API not reachable at {$this->baseUrl}. " .
                "Start it with: php -S localhost:8000 -t public/"
            );
        }

        // Create a fresh flash-sale product for this test run
        $this->productId = $this->createFlashSaleProduct();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * @group race-condition
     */
    public function it_prevents_overselling_during_a_flash_sale(): void
    {
        $concurrency = self::CONCURRENT_REQUESTS;
        $stock       = self::FLASH_SALE_STOCK;

        echo "\n";
        echo "  ┌─────────────────────────────────────────────────────┐\n";
        echo "  │ Flash-sale race-condition test                       │\n";
        echo "  │ Product #$this->productId │ inventory: $stock │ concurrent requests: $concurrency  │\n";
        echo "  └─────────────────────────────────────────────────────┘\n";

        // ── 1. Fire concurrent requests ───────────────────────────────────
        $results = $this->fireParallelOrders($this->productId, 1, $concurrency);

        // ── 2. Tally outcomes ─────────────────────────────────────────────
        $successes    = 0;
        $outOfStock   = 0;
        $otherErrors  = 0;

        foreach ($results as $r) {
            if ($r['status'] === 201) {
                $successes++;
            } elseif ($r['status'] === 409) {
                $outOfStock++;
            } else {
                $otherErrors++;
                echo "  ⚠ Unexpected response {$r['status']}: " . json_encode($r['body']) . "\n";
            }
        }

        echo "  Results → ✓ success: $successes  ✗ out-of-stock: $outOfStock  ? other: $otherErrors\n";

        // ── 3. Assertions ─────────────────────────────────────────────────

        // No unexpected errors
        $this->assertSame(
            0,
            $otherErrors,
            "There were $otherErrors unexpected (non-201, non-409) responses."
        );

        // Exactly `stock` orders succeeded
        $this->assertSame(
            $stock,
            $successes,
            "Expected exactly $stock successful orders (= initial inventory), got $successes."
        );

        // The rest were correctly rejected
        $this->assertSame(
            $concurrency - $stock,
            $outOfStock,
            "Expected " . ($concurrency - $stock) . " out-of-stock rejections, got $outOfStock."
        );

        // ── 4. Verify final inventory in the database ─────────────────────
        $product = $this->httpGet("/products/{$this->productId}");
        $finalInventory = (int) ($product['body']['inventory'] ?? -999);

        echo "  Final inventory in DB: $finalInventory (expected: 0)\n";

        $this->assertSame(
            0,
            $finalInventory,
            "Inventory must be exactly 0 after $stock units sold, got $finalInventory."
        );

        $this->assertGreaterThanOrEqual(
            0,
            $finalInventory,
            "Inventory went NEGATIVE ($finalInventory) – race condition was NOT handled!"
        );
    }

    /**
     * @test
     * @group validation
     */
    public function it_rejects_an_order_with_no_items(): void
    {
        $response = $this->httpPost('/orders', ['items' => []]);
        $this->assertSame(422, $response['status']);
    }

    /**
     * @test
     * @group validation
     */
    public function it_rejects_an_order_for_a_nonexistent_product(): void
    {
        $response = $this->httpPost('/orders', [
            'items' => [['product_id' => 999999, 'quantity' => 1]],
        ]);
        $this->assertSame(404, $response['status']);
    }

    /**
     * @test
     * @group validation
     */
    public function it_rejects_an_order_exceeding_available_inventory(): void
    {
        // The product still has 0 units (from the race-condition test), OR we
        // create a fresh product with only 1 unit and ask for 100.
        $id = $this->createProductWithStock(1);

        $response = $this->httpPost('/orders', [
            'items' => [['product_id' => $id, 'quantity' => 100]],
        ]);
        $this->assertSame(409, $response['status']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fire $count parallel HTTP POST /orders requests using curl_multi.
     * Each request tries to buy $qty units of $productId.
     *
     * Returns an array of ['status' => int, 'body' => array] maps.
     */
    private function fireParallelOrders(int $productId, int $qty, int $count): array
    {
        $body    = json_encode(['items' => [['product_id' => $productId, 'quantity' => $qty]]]);
        $handles = [];
        $multi   = curl_multi_init();

        for ($i = 0; $i < $count; $i++) {
            $ch = curl_init("{$this->baseUrl}/orders");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 15,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }

        // Execute all requests in parallel
        $active = null;
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi);
            }
        } while ($active > 0 && $status === CURLM_OK);

        // Collect results
        $results = [];
        foreach ($handles as $ch) {
            $results[] = [
                'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'body'   => json_decode(curl_multi_getcontent($ch), true) ?? [],
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);
        return $results;
    }

    /** POST /products to create a flash-sale product; return its ID. */
    private function createFlashSaleProduct(): int
    {
        return $this->createProductWithStock(self::FLASH_SALE_STOCK, true);
    }

    /** Helper to create a product with given stock (optionally as flash sale). */
    private function createProductWithStock(int $stock, bool $flashSale = false): int
    {
        $response = $this->httpPost('/products', [
            'name'        => 'Test Product ' . uniqid(),
            'description' => 'Created by RaceConditionTest',
            'price'       => 99.99,
            'flash_price' => $flashSale ? 9.99 : null,
            'flash_sale'  => $flashSale ? 1 : 0,
            'inventory'   => $stock,
        ]);

        $this->assertSame(
            201,
            $response['status'],
            'Failed to create test product: ' . json_encode($response['body'])
        );

        return (int) $response['body']['id'];
    }

    /** Perform a GET request and return ['status', 'body']. */
    private function httpGet(string $path): array
    {
        $ch = curl_init("{$this->baseUrl}{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => json_decode($raw ?: '{}', true) ?? []];
    }

    /** Perform a POST request and return ['status', 'body']. */
    private function httpPost(string $path, array $data): array
    {
        $ch = curl_init("{$this->baseUrl}{$path}");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => json_decode($raw ?: '{}', true) ?? []];
    }
}
