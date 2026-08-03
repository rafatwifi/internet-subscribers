-- ترتيب الباقات (يُطبَّق تلقائياً من PHP أيضاً)
ALTER TABLE service_plans
  ADD COLUMN sort_order INT NOT NULL DEFAULT 100 AFTER cost_price;

UPDATE service_plans SET sort_order = id * 10 WHERE sort_order = 100 OR sort_order IS NULL;

-- مثال يدوي:
-- UPDATE service_plans SET sort_order = 1 WHERE name LIKE '%MAX%';
-- UPDATE service_plans SET sort_order = 2 WHERE name LIKE '%NB-2%';
-- UPDATE service_plans SET sort_order = 3 WHERE name LIKE '%NB-3%';
-- UPDATE service_plans SET sort_order = 4 WHERE name LIKE '%NB-4%';
