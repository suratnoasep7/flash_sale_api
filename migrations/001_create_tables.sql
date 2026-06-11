-- ============================================================
-- Flash-Sale API – database schema
-- Run once to set up the database.
-- ============================================================

CREATE DATABASE IF NOT EXISTS flash_sale
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE flash_sale;

-- ── Products ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255)   NOT NULL,
    description   TEXT,
    price         DECIMAL(10,2)  NOT NULL,
    flash_price   DECIMAL(10,2)  DEFAULT NULL COMMENT 'Discounted price during flash sale',
    flash_sale    TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '1 = flash sale active',
    inventory     INT            NOT NULL DEFAULT 0 COMMENT 'Available stock; must never go below 0',
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_inventory_non_negative CHECK (inventory >= 0)
) ENGINE=InnoDB;

-- ── Orders ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS orders (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status      ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
    total       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Order Items ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS order_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    quantity    INT UNSIGNED NOT NULL,
    unit_price  DECIMAL(10,2) NOT NULL COMMENT 'Price captured at time of order',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── Seed data ─────────────────────────────────────────────
INSERT INTO products (name, description, price, flash_price, flash_sale, inventory)
VALUES
    ('Wireless Headphones', 'Premium noise-cancelling headphones', 299.99, 49.99, 1, 10),
    ('Smart Watch',         '4G-enabled fitness tracker',          199.99, 39.99, 0, 50),
    ('Mechanical Keyboard', 'TKL layout, RGB backlight',           149.99, 29.99, 0, 25);
