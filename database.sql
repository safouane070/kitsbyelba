-- ============================================
-- KitsByElbaa — Full Database Setup
-- Run in phpMyAdmin → Import  OR:
--   mysql -u root < database.sql
-- ============================================

CREATE DATABASE IF NOT EXISTS kitsbyelba
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE kitsbyelba;

-- ── ORDERS ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS orders (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NULL,
  order_id      VARCHAR(20)   NOT NULL UNIQUE,
  customer_name VARCHAR(120)  NOT NULL,
  email         VARCHAR(180)  NOT NULL,
  phone         VARCHAR(40)   NOT NULL,
  street        VARCHAR(200)  NOT NULL,
  zip           VARCHAR(20)   NOT NULL,
  city          VARCHAR(100)  NOT NULL,
  country       CHAR(2)       NOT NULL DEFAULT 'NL',
  notes         TEXT          DEFAULT NULL,
  admin_note    TEXT          DEFAULT NULL,
  tracking_number VARCHAR(64) DEFAULT NULL,
  coupon_code   VARCHAR(50)   DEFAULT NULL,
  discount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  subtotal      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  shipping      DECIMAL(10,2) NOT NULL DEFAULT 4.99,
  total         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status        ENUM('pending','confirmed','paid','shipped','delivered','cancelled')
                              NOT NULL DEFAULT 'pending',
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── ORDER ITEMS ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS order_items (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED    NOT NULL,
  product_id  INT             NOT NULL DEFAULT 0,
  name        VARCHAR(160)    NOT NULL,
  size        VARCHAR(10)     NOT NULL,
  quantity    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  price       DECIMAL(10,2)   NOT NULL,
  printing_option VARCHAR(20) DEFAULT 'none',  -- none|custom
  print_name     VARCHAR(60) DEFAULT NULL,
  print_number   VARCHAR(10) DEFAULT NULL,
  print_badges   VARCHAR(80) DEFAULT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PRODUCTS ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(160)          NOT NULL,
  league     VARCHAR(80)           NOT NULL,
  cat        VARCHAR(40)           NOT NULL DEFAULT '',
  emoji      VARCHAR(10)           NOT NULL DEFAULT '👕',
  description TEXT                DEFAULT NULL,
  fit_info   TEXT                 DEFAULT NULL,
  size_advice TEXT                DEFAULT NULL,
  material_info TEXT              DEFAULT NULL,
  shipping_info TEXT              DEFAULT NULL,
  returns_info TEXT               DEFAULT NULL,
  personalization_policy TEXT     DEFAULT NULL,
  care_instructions TEXT          DEFAULT NULL,
  image_order VARCHAR(20)         NOT NULL DEFAULT '1,2,3',
  stock      INT                  NOT NULL DEFAULT 10,
  price      DECIMAL(10,2)         NOT NULL,
  badge      ENUM('','new','hot')  NOT NULL DEFAULT '',
  image      VARCHAR(255)          DEFAULT NULL,
  image2     VARCHAR(255)          DEFAULT NULL,
  image3     VARCHAR(255)          DEFAULT NULL,
  active     TINYINT(1)            NOT NULL DEFAULT 1,
  sort_order INT                   NOT NULL DEFAULT 0,
  kits_path     VARCHAR(512)       DEFAULT NULL,
  created_at DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_kits_path (kits_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SEED PRODUCTS ─────────────────────────────────────
INSERT INTO products (name, league, cat, emoji, price, badge, sort_order) VALUES
('Manchester United Home', 'Premier League', 'premier',    '🔴', 29.99, 'hot', 1),
('Barcelona Away',         'La Liga',        'laliga',     '🔵', 29.99, 'new', 2),
('Real Madrid Home',       'La Liga',        'laliga',     '⚪', 34.99, '',    3),
('Ajax Home',              'Eredivisie',     'eredivisie', '🟠', 27.99, 'new', 4),
('PSG Third Kit',          'Ligue 1',        '',           '🖤', 29.99, '',    5),
('Arsenal Home',           'Premier League', 'premier',    '❤️', 29.99, 'hot', 6),
('Bayern München Home',    'Bundesliga',     'bundesliga', '🟥', 32.99, 'hot', 7),
('Juventus Home',          'Serie A',        'seriea',     '🖤', 29.99, '',    8),
('Netherlands Home',       'National Teams', 'national',   '🟧', 34.99, 'new', 9),
('Manchester City Away',   'Premier League', 'premier',    '🩵', 29.99, '',   10),
('Borussia Dortmund Home', 'Bundesliga',     'bundesliga', '💛', 29.99, 'new',11),
('Feyenoord Home',         'Eredivisie',     'eredivisie', '🔴', 27.99, '',   12);

-- ── INDEXES ───────────────────────────────────────────
CREATE INDEX idx_orders_email   ON orders(email);
CREATE INDEX idx_orders_status  ON orders(status);
CREATE INDEX idx_orders_user_id ON orders(user_id);
CREATE INDEX idx_items_order    ON order_items(order_id);
CREATE INDEX idx_products_cat   ON products(cat);
CREATE INDEX idx_products_active ON products(active);

-- ── SITE SETTINGS (promo banner / FAQ — seeded on first admin load) ──
CREATE TABLE IF NOT EXISTS site_settings (
  `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
  `value` MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── USERS ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120)  NOT NULL,
  email         VARCHAR(254)  NOT NULL UNIQUE,
  password_hash VARCHAR(255)  NOT NULL,
  phone         VARCHAR(30)   DEFAULT NULL,
  street        VARCHAR(200)  DEFAULT NULL,
  zip           VARCHAR(20)   DEFAULT NULL,
  city          VARCHAR(100)  DEFAULT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── STOCK NOTIFICATIONS ───────────────────────────────
CREATE TABLE IF NOT EXISTS stock_notifications (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(254) NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  size       VARCHAR(10)  NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stock_notify (email, product_id, size),
  KEY idx_stock_notify_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── COUPONS ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS coupons (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(50)  NOT NULL UNIQUE,
  type       ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value      DECIMAL(10,2) NOT NULL DEFAULT 10.00,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  uses_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
