<?php

namespace App\Controllers;

use App\Database\Database;
use App\Models\Order;
use App\Models\Product;
use PDO;
use Throwable;

/**
 * OrderController
 *
 * Handles order creation and retrieval.
 *
 * Race-condition protection
 * ─────────────────────────
 * During a flash sale many concurrent requests can arrive at the same time,
 * all attempting to buy the same product.  Without proper locking two
 * concurrent transactions could both read inventory = 1, both decide "ok to
 * proceed", and both decrement it – resulting in inventory = -1.
 *
 * We prevent this with a two-layer defence:
 *
 *  1. Pessimistic row lock (SELECT … FOR UPDATE)
 *     The product row is locked for the duration of the transaction.
 *     Any other transaction trying to read the same row FOR UPDATE will
 *     block until the first one commits or rolls back.
 *
 *  2. Atomic guard in the UPDATE
 *     Even if two transactions somehow pass the application-level check
 *     simultaneously, the WHERE inventory >= qty clause in decrementInventory()
 *     means MySQL will simply update 0 rows instead of going negative.
 *     We treat 0 affected rows as an out-of-stock signal.
 */
class OrderController extends BaseController
{
    private Order   $order;
    private Product $product;
    private PDO     $db;

    public function __construct()
    {
        $this->order   = new Order();
        $this->product = new Product();
        $this->db      = Database::getInstance();
    }

    /** GET /orders */
    public function index(): never
    {
        $this->json($this->order->getAll());
    }

    /** GET /orders/{id} */
    public function show(int $id): never
    {
        $order = $this->order->findById($id);

        if (!$order) {
            $this->notFound("Order #$id not found");
        }

        $this->json($order);
    }

    /**
     * POST /orders
     *
     * Expected body:
     * {
     *   "items": [
     *     { "product_id": 1, "quantity": 2 }
     *   ]
     * }
     *
     * Race-condition flow
     * ───────────────────
     * BEGIN TRANSACTION
     *   For each item:
     *     SELECT … FOR UPDATE  ← acquires row lock; concurrent requests queue here
     *     Validate stock
     *     UPDATE inventory     ← atomic guard: WHERE inventory >= qty
     *   INSERT order + items
     * COMMIT                   ← releases all locks
     */
    public function store(): never
    {
        $data  = $this->getRequestBody();
        $items = $data['items'] ?? [];

        // ── Validate request structure ────────────────────────────────────
        if (empty($items) || !is_array($items)) {
            $this->validationError('Field "items" must be a non-empty array');
        }

        foreach ($items as $i => $item) {
            if (empty($item['product_id']) || !is_int($item['product_id'])) {
                $this->validationError("items[$i].product_id must be a positive integer");
            }
            if (empty($item['quantity']) || !is_int($item['quantity']) || $item['quantity'] < 1) {
                $this->validationError("items[$i].quantity must be a positive integer");
            }
        }

        // ── Begin transaction ─────────────────────────────────────────────
        $this->db->beginTransaction();

        try {
            $orderId    = $this->order->create();
            $lineItems  = [];

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $quantity  = (int) $item['quantity'];

                // Lock the product row for the duration of this transaction.
                // Concurrent requests will wait here until we commit/rollback.
                $product = $this->product->findByIdForUpdate($productId);

                if (!$product) {
                    $this->db->rollBack();
                    $this->notFound("Product #$productId not found");
                }

                // Determine the effective price (flash sale takes priority)
                $unitPrice = ($product['flash_sale'] && $product['flash_price'] !== null)
                    ? (float) $product['flash_price']
                    : (float) $product['price'];

                // Application-level stock check (fast, human-readable error)
                if ($product['inventory'] < $quantity) {
                    $this->db->rollBack();
                    $this->json([
                        'error'     => 'Insufficient inventory',
                        'product'   => $product['name'],
                        'available' => (int) $product['inventory'],
                        'requested' => $quantity,
                    ], 409);
                }

                // Atomic decrement – the WHERE guard is the final safety net
                $decremented = $this->product->decrementInventory($productId, $quantity);

                if (!$decremented) {
                    // Another concurrent transaction just grabbed the last units
                    $this->db->rollBack();
                    $this->json([
                        'error'   => 'Product sold out – please try again',
                        'product' => $product['name'],
                    ], 409);
                }

                // Record the line item
                $this->order->addItem($orderId, $productId, $quantity, $unitPrice);

                $lineItems[] = [
                    'product_id'   => $productId,
                    'product_name' => $product['name'],
                    'quantity'     => $quantity,
                    'unit_price'   => $unitPrice,
                ];
            }

            // Confirm the order
            $this->order->confirm($orderId);

            $this->db->commit();

            // Return the freshly created order
            $this->json($this->order->findById($orderId), 201);

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->serverError('Order failed: ' . $e->getMessage());
        }
    }
}
