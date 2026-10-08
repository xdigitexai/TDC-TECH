CREATE TABLE IF NOT EXISTS customer_profiles (
 user_id BIGINT UNSIGNED PRIMARY KEY, bio VARCHAR(600) NOT NULL DEFAULT '', avatar_url VARCHAR(255) NOT NULL DEFAULT '',
 timezone VARCHAR(80) NOT NULL DEFAULT 'Africa/Nairobi', theme ENUM('dark','light','system') NOT NULL DEFAULT 'dark',
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS support_tickets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
 request_id VARCHAR(80) NOT NULL,subject VARCHAR(160) NOT NULL,order_id BIGINT UNSIGNED NULL,
 status ENUM('open','answered','closed') NOT NULL DEFAULT 'open',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY customer_request(user_id,request_id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS support_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ticket_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,body TEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(ticket_id) REFERENCES support_tickets(id),FOREIGN KEY(user_id) REFERENCES users(id),INDEX(ticket_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS checkout_receipts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,request_id VARCHAR(80) NOT NULL,
 request_hash CHAR(64) NOT NULL,order_ids JSON NOT NULL,amount DECIMAL(12,2) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY checkout_request(user_id,request_id),FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
INSERT INTO services(slug,name,price,pricing_mode,active) VALUES
('template-business','Business Landing Pro',49,'fixed',1),('template-artist','Artist Portfolio',79,'fixed',1),
('template-commerce','E-commerce Starter',149,'fixed',1),('template-pwa','PWA App Shell',99,'fixed',1),
('template-social','Chat & Social App UI',129,'fixed',1),('template-agency','Agency Services Site',89,'fixed',1),
('template-merch','Merch + Music Store',169,'fixed',1),('template-smm','SMM Panel Dashboard',199,'fixed',1)
ON DUPLICATE KEY UPDATE slug=VALUES(slug);
INSERT IGNORE INTO service_page_services(page_slug,service_id) SELECT 'web-dev',id FROM services WHERE slug IN
('template-business','template-artist','template-commerce','template-pwa','template-social','template-agency','template-merch','template-smm');
