CREATE TABLE IF NOT EXISTS icdm_subscribers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  full_name VARCHAR(120) NULL,
  status ENUM('pending','active','unsubscribed') NOT NULL DEFAULT 'pending',
  confirm_token_hash CHAR(64) NOT NULL,
  unsubscribe_token_hash CHAR(64) NOT NULL,
  consent_marketing TINYINT(1) NOT NULL DEFAULT 0,
  consent_at DATETIME NULL,
  privacy_version VARCHAR(50) NULL,
  privacy_url VARCHAR(255) NULL,
  consent_text TEXT NULL,
  confirm_expires_at DATETIME NULL,
  confirmed_at DATETIME NULL,
  unsubscribed_at DATETIME NULL,
  source_page VARCHAR(120) NULL,
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email (email),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS icdm_subscriber_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('subscribe_request','subscribe_confirmed','unsubscribe_request','unsubscribe_confirmed') NOT NULL,
  event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARBINARY(16) NULL,
  note VARCHAR(255) NULL,
  CONSTRAINT fk_icdm_events_subscriber
    FOREIGN KEY (subscriber_id) REFERENCES icdm_subscribers(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
