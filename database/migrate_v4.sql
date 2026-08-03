-- سجل التغييرات (ديون وغيرها)
USE internet_subs;

CREATE TABLE IF NOT EXISTS activity_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscriber_id INT UNSIGNED DEFAULT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(40) NOT NULL,
  summary VARCHAR(255) NOT NULL,
  details TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_sub (subscriber_id),
  INDEX idx_activity_created (created_at),
  INDEX idx_activity_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
