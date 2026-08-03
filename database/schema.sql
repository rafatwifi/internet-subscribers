-- قاعدة بيانات نظام مشتركي الإنترنت (النسخة الكاملة)
CREATE DATABASE IF NOT EXISTS internet_subs
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE internet_subs;

CREATE TABLE IF NOT EXISTS subscribers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  address VARCHAR(255) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  monthly_price DECIMAL(12,0) NOT NULL DEFAULT 0,
  cost_price DECIMAL(12,0) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscriber_id INT UNSIGNED NOT NULL,
  service_name VARCHAR(100) NOT NULL,
  monthly_price DECIMAL(12,0) NOT NULL,
  cost_price DECIMAL(12,0) NOT NULL DEFAULT 0,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  activation_msg_sent TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sub_subscriber
    FOREIGN KEY (subscriber_id) REFERENCES subscribers(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NULL,
  subscriber_id INT UNSIGNED NOT NULL,
  month_label VARCHAR(20) NOT NULL,
  amount DECIMAL(12,0) NOT NULL,
  cost_price DECIMAL(12,0) NOT NULL DEFAULT 0,
  profit DECIMAL(12,0) NOT NULL DEFAULT 0,
  due_date DATE NOT NULL,
  status ENUM('unpaid','paid','waived') NOT NULL DEFAULT 'unpaid',
  paid_at DATETIME DEFAULT NULL,
  reminder_sent_at DATETIME DEFAULT NULL,
  reminder_count INT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_subscription
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_inv_subscriber
    FOREIGN KEY (subscriber_id) REFERENCES subscribers(id)
    ON DELETE CASCADE,
  INDEX idx_status_due (status, due_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscriber_id INT UNSIGNED DEFAULT NULL,
  phone VARCHAR(30) NOT NULL,
  message_type VARCHAR(50) NOT NULL,
  body TEXT NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  response_json TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO service_plans (name, monthly_price, cost_price) VALUES
('إنترنت 20 ميجا', 25000, 15000),
('إنترنت 50 ميجا', 40000, 25000),
('إنترنت 100 ميجا', 60000, 40000);
