<?php

namespace App\Models;

use App\Database\Database;
use PDO;

/**
 * Product model.
 *
 * Handles all database operations for products, including the
 * SELECT … FOR UPDATE locking needed during flash-sale purchases.
 */
class Product
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** Return all products. */
    public function getAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM products ORDER BY id');
        return $stmt->fetchAll();
    }

    /** Return a single product by ID, or null if not found. */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Fetch a product with a pessimistic write-lock (SELECT … FOR UPDATE).
     *
     * MUST be called inside an active transaction so the lock is held
     * until the transaction commits or rolls back.
     *
     * @param int $id Product ID
     * @return array|null Locked product row, or null if not found
     */
    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM products WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Insert a new product and return its ID. */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO products (name, description, price, flash_price, flash_sale, inventory)
             VALUES (:name, :description, :price, :flash_price, :flash_sale, :inventory)'
        );
        $stmt->execute([
            ':name'        => $data['name'],
            ':description' => $data['description'] ?? null,
            ':price'       => $data['price'],
            ':flash_price' => $data['flash_price'] ?? null,
            ':flash_sale'  => $data['flash_sale']  ?? 0,
            ':inventory'   => $data['inventory']   ?? 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Update a product's fields (partial update safe). */
    public function update(int $id, array $data): bool
    {
        $allowed = ['name', 'description', 'price', 'flash_price', 'flash_sale', 'inventory'];
        $fields  = [];
        $params  = [':id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql  = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Decrement inventory by $qty.
     *
     * Uses a WHERE clause guard (inventory >= qty) so MySQL will refuse to
     * apply the update – and return 0 affected rows – rather than produce a
     * negative value, even if the CHECK constraint is not enforced at runtime.
     *
     * @param int $id  Product ID
     * @param int $qty Units to deduct
     * @return bool    True if inventory was successfully decremented
     */
    public function decrementInventory(int $id, int $qty): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE products
             SET inventory = inventory - :qty
             WHERE id = :id
               AND inventory >= :qty2'
        );
        $stmt->execute([':qty' => $qty, ':id' => $id, ':qty2' => $qty]);
        return $stmt->rowCount() > 0;
    }

    /** Delete a product. Returns true when a row was actually deleted. */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
