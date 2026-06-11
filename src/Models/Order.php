<?php

namespace App\Models;

use App\Database\Database;
use PDO;

/**
 * Order model.
 *
 * Manages orders and their associated order items.
 * All multi-step writes are expected to run inside a transaction
 * owned by the calling controller.
 */
class Order
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** Return all orders (with their items). */
    public function getAll(): array
    {
        $orders = $this->db->query(
            'SELECT * FROM orders ORDER BY created_at DESC'
        )->fetchAll();

        foreach ($orders as &$order) {
            $order['items'] = $this->getItems((int) $order['id']);
        }

        return $orders;
    }

    /** Return a single order with its items, or null. */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $order = $stmt->fetch();

        if (!$order) {
            return null;
        }

        $order['items'] = $this->getItems($id);
        return $order;
    }

    /**
     * Create a bare order row and return its new ID.
     * Items are inserted separately via addItem().
     */
    public function create(): int
    {
        $this->db->exec(
            "INSERT INTO orders (status, total) VALUES ('pending', 0.00)"
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * Add an item to an order and update the order total.
     *
     * @param int   $orderId   Parent order
     * @param int   $productId Product being ordered
     * @param int   $quantity  Units requested
     * @param float $unitPrice Price locked at order time
     */
    public function addItem(int $orderId, int $productId, int $quantity, float $unitPrice): void
    {
        // Insert the line item
        $stmt = $this->db->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, unit_price)
             VALUES (:order_id, :product_id, :quantity, :unit_price)'
        );
        $stmt->execute([
            ':order_id'   => $orderId,
            ':product_id' => $productId,
            ':quantity'   => $quantity,
            ':unit_price' => $unitPrice,
        ]);

        // Keep the order total in sync
        $stmt = $this->db->prepare(
            'UPDATE orders
             SET total = total + :line_total
             WHERE id = :order_id'
        );
        $stmt->execute([
            ':line_total' => $quantity * $unitPrice,
            ':order_id'   => $orderId,
        ]);
    }

    /**
     * Confirm a pending order.
     *
     * @return bool False when the order didn't exist or wasn't pending.
     */
    public function confirm(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET status = 'confirmed' WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Return all line items for an order. */
    public function getItems(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT oi.*, p.name AS product_name
             FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }
}
