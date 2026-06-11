# Flash-Sale API

A PHP REST API for an online store that safely handles flash-sale race conditions using MySQL row-level locking.

---

## Architecture

```
flash-sale-api/
├── config/
│   └── database.php          # DB config (env-var overridable)
├── migrations/
│   └── 001_create_tables.sql # Full schema + seed data
├── public/
│   ├── index.php             # Front controller
│   └── .htaccess             # Apache URL rewriting
├── src/
│   ├── Controllers/
│   │   ├── BaseController.php   # JSON helpers
│   │   ├── ProductController.php
│   │   └── OrderController.php  # ← race-condition logic lives here
│   ├── Database/
│   │   └── Database.php         # PDO singleton
│   ├── Middleware/
│   │   └── Router.php           # Regex-based router
│   └── Models/
│       ├── Order.php
│       └── Product.php          # decrementInventory() atomic guard
└── tests/
    └── RaceConditionTest.php    # Functional test (curl_multi)
```

---

## Race Condition Protection

The two-layer defence used in `OrderController::store()`:

### Layer 1 – Pessimistic row lock (`SELECT … FOR UPDATE`)

```sql
SELECT * FROM products WHERE id = ? FOR UPDATE
```

When Transaction A acquires this lock, Transaction B's identical query
**blocks** until A commits or rolls back. This serialises access to the
product row, preventing two transactions from simultaneously reading
`inventory = 1` and both deciding they can proceed.

### Layer 2 – Atomic `WHERE` guard in the `UPDATE`

```sql
UPDATE products
SET inventory = inventory - :qty
WHERE id = :id
  AND inventory >= :qty
```

Even in a highly concurrent environment, the `WHERE inventory >= qty`
clause ensures MySQL will update **0 rows** rather than decrement below
zero. We treat 0 affected rows as an out-of-stock signal and return HTTP 409.

---

## Requirements

- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+ (InnoDB engine for row-level locking)
- Composer
- PHP extensions: `pdo`, `pdo_mysql`, `curl`, `json`

---

## Setup

### 1. Install dependencies

```bash
composer install
```

### 2. Create the database and run migrations

```bash
mysql -u root -p < migrations/001_create_tables.sql
```

### 3. Configure environment (optional)

The defaults match a local MySQL install with no password. Override with
environment variables:

```bash
export DB_HOST=localhost
export DB_PORT=3306
export DB_NAME=flash_sale
export DB_USER=root
export DB_PASSWORD=secret
```

### 4. Start the development server

```bash
php -S localhost:8000 -t public/
```

---

## Running the Tests

```bash
# In a separate terminal, make sure the server is running:
php -S localhost:8000 -t public/

# Run all tests:
./vendor/bin/phpunit --testdox

# Run only the race-condition test:
./vendor/bin/phpunit --testdox --group race-condition

# Point tests at a different server:
API_BASE_URL=http://my-server ./vendor/bin/phpunit
```

The race-condition test:

1. Creates a flash-sale product with **5 units** in stock.
2. Fires **20 concurrent** `POST /orders` requests via `curl_multi_exec`.
3. Asserts that exactly **5 succeed** (HTTP 201) and **15 are rejected** (HTTP 409).
4. Verifies the final `inventory` in the database is exactly **0** (never negative).

---

## API Reference

### Health

| Method | Path      | Description  |
|--------|-----------|--------------|
| GET    | /health   | Health check |

**Response `200`:**
```json
{ "status": "ok", "timestamp": "2024-01-01T12:00:00+00:00" }
```

---

### Products

| Method | Path            | Description         |
|--------|-----------------|---------------------|
| GET    | /products       | List all products   |
| GET    | /products/{id}  | Get single product  |
| POST   | /products       | Create a product    |
| PUT    | /products/{id}  | Update a product    |
| DELETE | /products/{id}  | Delete a product    |

**Create / Update body:**
```json
{
  "name":        "Wireless Headphones",
  "description": "Premium noise-cancelling",
  "price":       299.99,
  "flash_price": 49.99,
  "flash_sale":  1,
  "inventory":   10
}
```

---

### Orders

| Method | Path         | Description        |
|--------|--------------|--------------------|
| GET    | /orders      | List all orders    |
| GET    | /orders/{id} | Get single order   |
| POST   | /orders      | Create an order    |

**Create order body:**
```json
{
  "items": [
    { "product_id": 1, "quantity": 1 }
  ]
}
```

**Success `201`:**
```json
{
  "id": 42,
  "status": "confirmed",
  "total": "49.99",
  "items": [
    {
      "id": 1,
      "order_id": 42,
      "product_id": 1,
      "product_name": "Wireless Headphones",
      "quantity": 1,
      "unit_price": "49.99"
    }
  ]
}
```

**Out-of-stock `409`:**
```json
{
  "error": "Insufficient inventory",
  "product": "Wireless Headphones",
  "available": 0,
  "requested": 1
}
```

---

## Error Codes

| Code | Meaning                                     |
|------|---------------------------------------------|
| 400  | Invalid or missing JSON body                |
| 404  | Resource not found                          |
| 405  | HTTP method not allowed                     |
| 409  | Conflict – insufficient inventory           |
| 422  | Validation error (missing / invalid fields) |
| 500  | Unhandled server error                      |

---

## Cara Penggunaan (Windows / XAMPP)

### Persiapan Awal

**1. Install XAMPP**
Download di https://www.apachefriends.org → install → buka XAMPP Control Panel → klik **Start** pada **Apache** dan **MySQL** (keduanya harus hijau).

**2. Install Composer**
Download di https://getcomposer.org/Composer-Setup.exe → install → restart Command Prompt.

---

### Setup Project

**3. Extract project**
Extract file `flash-sale-api.zip` ke folder:
```
C:\xampp\htdocs\flash-sale-api
```

**4. Install dependensi**
Buka Command Prompt:
```cmd
cd C:\xampp\htdocs\flash-sale-api
composer install
```
Tunggu sampai selesai (akan membuat folder `vendor/`).

---

### Setup Database

**5. Buka phpMyAdmin**
Buka browser → ketik `http://localhost/phpmyadmin`

**6. Buat database**
Klik **New** di sebelah kiri → isi nama: `flash_sale` → klik **Create**

**7. Jalankan migrasi**
Klik tab **SQL** → buka file `migrations/001_create_tables.sql` dengan Notepad → **Select All → Copy** → paste di kotak SQL → klik **Go**

Pastikan 3 tabel berhasil dibuat:
```
✅ products
✅ orders
✅ order_items
```

---

### Menjalankan Server

**8. Jalankan PHP server**
Buka Command Prompt → ketik:
```cmd
C:\xampp\php\php.exe -S localhost:8000 -t public/
```
Biarkan terminal ini **terbuka terus**.

**9. Cek API berjalan**
Buka browser → ketik:
```
http://localhost:8000/health
```
Harus muncul:
```json
{"status":"ok","timestamp":"..."}
```

---

### Contoh Penggunaan API

Buka Command Prompt baru (server tetap jalan), lalu coba:

**Lihat semua produk:**
```cmd
curl http://localhost:8000/products
```

**Lihat satu produk:**
```cmd
curl http://localhost:8000/products/1
```

**Tambah produk baru:**
```cmd
curl -X POST http://localhost:8000/products ^
  -H "Content-Type: application/json" ^
  -d "{\"name\":\"Laptop Gaming\",\"price\":999.99,\"inventory\":5}"
```

**Buat pesanan:**
```cmd
curl -X POST http://localhost:8000/orders ^
  -H "Content-Type: application/json" ^
  -d "{\"items\":[{\"product_id\":1,\"quantity\":1}]}"
```

**Lihat semua pesanan:**
```cmd
curl http://localhost:8000/orders
```

**Update produk:**
```cmd
curl -X PUT http://localhost:8000/products/1 ^
  -H "Content-Type: application/json" ^
  -d "{\"inventory\":20}"
```

**Hapus produk:**
```cmd
curl -X DELETE http://localhost:8000/products/1
```

---

### Menjalankan Tes Race Condition

**Terminal 1** – jalankan server (jika belum):
```cmd
C:\xampp\php\php.exe -S localhost:8000 -t public/
```

**Terminal 2** – jalankan tes:
```cmd
cd C:\xampp\htdocs\flash-sale-api
vendor\bin\phpunit --testdox
```

Output yang diharapkan:
```
Flash-sale race-condition test
Product #4 | inventory: 5 | concurrent requests: 20
Results → ✓ success: 5  ✗ out-of-stock: 15  ? other: 0
Final inventory in DB: 0 (expected: 0)

✓ it prevents overselling during a flash sale
✓ it rejects an order with no items
✓ it rejects an order for a nonexistent product
✓ it rejects an order exceeding available inventory

OK (4 tests, 8 assertions)
```

---

### Troubleshooting

| Masalah | Solusi |
|---------|--------|
| `curl` tidak dikenal | Gunakan PowerShell, bukan CMD |
| `composer` tidak dikenal | Restart CMD setelah install Composer |
| Database connection failed | Pastikan MySQL sudah Start di XAMPP |
| Port 8000 sudah dipakai | Ganti ke port lain, misal `8080` |
| Test skipped | Server belum berjalan di localhost:8000 |

---

## Git – Upload ke Repository

### Persiapan Awal Git

**1. Install Git**
Download di https://git-scm.com/download/win → install → restart CMD.

**2. Cek Git sudah terinstall:**
```cmd
git --version
```
Harus muncul: `git version 2.x.x`

---

### Buat Repository di GitHub

**3.** Buka https://github.com → login → klik tombol **New** (repository baru)

**4.** Isi:
- Repository name: `flash-sale-api`
- Visibility: **Public** (agar bisa diakses dosen)
- Jangan centang apapun

**5.** Klik **Create repository** → copy URL yang muncul, contoh:
```
https://github.com/namakamu/flash-sale-api.git
```

---

### Upload Kode ke GitHub

Buka Command Prompt → masuk ke folder project:
```cmd
cd C:\xampp\htdocs\flash-sale-api
```

**6. Inisialisasi Git:**
```cmd
git init
```

**7. Set identitas (sekali saja):**
```cmd
git config --global user.name "Nama Kamu"
git config --global user.email "email@kamu.com"
```

**8. Tambahkan semua file:**
```cmd
git add .
```

**9. Commit pertama:**
```cmd
git commit -m "feat: initial project structure with PHP REST API"
```

**10. Hubungkan ke GitHub:**
```cmd
git remote add origin https://github.com/namakamu/flash-sale-api.git
```

**11. Upload ke GitHub:**
```cmd
git push -u origin main
```

---

### Contoh Commit Selanjutnya

Setiap kali ada perubahan, lakukan ini:

```cmd
git add .
git commit -m "pesan commit yang menjelaskan perubahan"
git push
```

Contoh pesan commit yang bermakna:
```cmd
git commit -m "feat: add product CRUD endpoints"
git commit -m "feat: add order creation with flash sale pricing"
git commit -m "fix: prevent negative inventory with SELECT FOR UPDATE"
git commit -m "test: add race condition functional test using curl_multi"
git commit -m "docs: add usage guide to README"
```

---

### Cek di Browser

Setelah push, buka:
```
https://github.com/namakamu/flash-sale-api
```
Pastikan semua file sudah muncul di sana. ✅
