<?php

namespace App\Controllers;

use App\Models\Product;

/**
 * ProductController
 *
 * Handles CRUD operations for the /products endpoints.
 */
class ProductController extends BaseController
{
    private Product $product;

    public function __construct()
    {
        $this->product = new Product();
    }

    /** GET /products */
    public function index(): never
    {
        $this->json($this->product->getAll());
    }

    /** GET /products/{id} */
    public function show(int $id): never
    {
        $product = $this->product->findById($id);

        if (!$product) {
            $this->notFound("Product #$id not found");
        }

        $this->json($product);
    }

    /** POST /products */
    public function store(): never
    {
        $data = $this->getRequestBody();

        // Validate required fields
        if (empty($data['name'])) {
            $this->validationError('Field "name" is required');
        }
        if (!isset($data['price']) || !is_numeric($data['price']) || $data['price'] < 0) {
            $this->validationError('Field "price" must be a non-negative number');
        }
        if (isset($data['flash_price']) && $data['flash_price'] !== null) {
            if (!is_numeric($data['flash_price']) || $data['flash_price'] < 0) {
                $this->validationError('Field "flash_price" must be a non-negative number');
            }
        }
        if (isset($data['inventory']) && (!is_int($data['inventory']) || $data['inventory'] < 0)) {
            $this->validationError('Field "inventory" must be a non-negative integer');
        }

        $id      = $this->product->create($data);
        $product = $this->product->findById($id);

        $this->json($product, 201);
    }

    /** PUT /products/{id} */
    public function update(int $id): never
    {
        if (!$this->product->findById($id)) {
            $this->notFound("Product #$id not found");
        }

        $data = $this->getRequestBody();

        // Validate any fields that were supplied
        if (array_key_exists('price', $data) && (!is_numeric($data['price']) || $data['price'] < 0)) {
            $this->validationError('Field "price" must be a non-negative number');
        }
        if (array_key_exists('inventory', $data) && (!is_int($data['inventory']) || $data['inventory'] < 0)) {
            $this->validationError('Field "inventory" must be a non-negative integer');
        }

        $this->product->update($id, $data);
        $this->json($this->product->findById($id));
    }

    /** DELETE /products/{id} */
    public function destroy(int $id): never
    {
        if (!$this->product->findById($id)) {
            $this->notFound("Product #$id not found");
        }

        $this->product->delete($id);
        $this->json(['message' => "Product #$id deleted"], 200);
    }
}
