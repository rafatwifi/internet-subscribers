-- أعمدة جهاز الإيجار للمشتركين
USE internet_subs;

ALTER TABLE subscribers
  ADD COLUMN IF NOT EXISTS rental_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
  ADD COLUMN IF NOT EXISTS rental_device_id VARCHAR(60) DEFAULT NULL AFTER rental_enabled;
