-- ترقية قاعدة البيانات للنسخة 2 (تشغيل مرة واحدة على السيرفر)
USE internet_subs;

ALTER TABLE service_plans
  ADD COLUMN cost_price DECIMAL(12,0) NOT NULL DEFAULT 0 AFTER monthly_price;

ALTER TABLE subscriptions
  ADD COLUMN cost_price DECIMAL(12,0) NOT NULL DEFAULT 0 AFTER monthly_price;

ALTER TABLE invoices
  ADD COLUMN cost_price DECIMAL(12,0) NOT NULL DEFAULT 0 AFTER amount,
  ADD COLUMN profit DECIMAL(12,0) NOT NULL DEFAULT 0 AFTER cost_price;
