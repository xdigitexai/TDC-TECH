CREATE TABLE IF NOT EXISTS smm_providers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL, api_url VARCHAR(500) NOT NULL, encrypted_key TEXT NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'USD', units_per_gbp DECIMAL(18,6) NOT NULL,
 profit_percent DECIMAL(10,2) NOT NULL DEFAULT 25, active TINYINT NOT NULL DEFAULT 1,
 last_synced_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS smm_services (
 service_id BIGINT UNSIGNED PRIMARY KEY, provider_id BIGINT UNSIGNED NOT NULL,
 remote_id VARCHAR(60) NOT NULL, category VARCHAR(255) NOT NULL, service_type VARCHAR(80) NOT NULL,
 provider_rate DECIMAL(18,6) NOT NULL, min_quantity INT UNSIGNED NOT NULL, max_quantity INT UNSIGNED NOT NULL,
 refill TINYINT NOT NULL DEFAULT 0, cancel TINYINT NOT NULL DEFAULT 0,
 UNIQUE KEY provider_service(provider_id,remote_id),
 FOREIGN KEY(service_id) REFERENCES services(id), FOREIGN KEY(provider_id) REFERENCES smm_providers(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS smm_jobs (
 order_id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, request_id VARCHAR(80) NOT NULL,
 request_hash CHAR(64) NOT NULL, provider_id BIGINT UNSIGNED NOT NULL, remote_service VARCHAR(60) NOT NULL,
 payload JSON NOT NULL, remote_order VARCHAR(80) NULL,
 state ENUM('queued','submitting','submitted','review','done','failed') NOT NULL DEFAULT 'queued',
 provider_status VARCHAR(80) NULL, remains INT UNSIGNED NULL, start_count VARCHAR(80) NULL,
 last_checked_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY user_request(user_id,request_id),
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(provider_id) REFERENCES smm_providers(id)
) ENGINE=InnoDB;
