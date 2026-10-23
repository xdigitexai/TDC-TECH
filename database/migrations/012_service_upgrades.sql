-- TDC Tech service upgrades (2026-10-23)
-- Ad rate card: admin-editable per-day price by category and placement.
CREATE TABLE IF NOT EXISTS ad_rates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(30) NOT NULL,
  placement VARCHAR(30) NOT NULL,
  price_per_day DECIMAL(10,2) NOT NULL DEFAULT 0,
  reach_per_eur INT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ad_rate(category,placement),
  INDEX idx_ad_rate_category(category,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ad_rates(category,placement,price_per_day,reach_per_eur,active) VALUES
('music','meta',14.00,800,1),('music','youtube',11.00,1200,1),('music','tiktok',9.00,1800,1),('music','linkedin',20.00,400,1),('music','x',8.00,700,1),('music','google',16.00,900,1),
('website','meta',16.00,700,1),('website','youtube',12.00,1000,1),('website','tiktok',10.00,1500,1),('website','linkedin',22.00,350,1),('website','x',9.00,600,1),('website','google',18.00,800,1),
('mobile_app','meta',18.00,650,1),('mobile_app','youtube',13.00,900,1),('mobile_app','tiktok',12.00,1300,1),('mobile_app','linkedin',24.00,320,1),('mobile_app','x',10.00,550,1),('mobile_app','google',20.00,750,1),
('business','meta',15.00,750,1),('business','youtube',10.00,1100,1),('business','tiktok',8.00,1600,1),('business','linkedin',26.00,300,1),('business','x',8.00,650,1),('business','google',20.00,850,1),
('social_media','meta',13.00,850,1),('social_media','youtube',9.00,1250,1),('social_media','tiktok',7.00,1900,1),('social_media','linkedin',18.00,450,1),('social_media','x',6.00,750,1),('social_media','google',14.00,950,1),
('custom','meta',17.00,700,1),('custom','youtube',12.00,1000,1),('custom','tiktok',10.00,1500,1),('custom','linkedin',24.00,350,1),('custom','x',9.00,600,1),('custom','google',19.00,800,1)
ON DUPLICATE KEY UPDATE price_per_day=VALUES(price_per_day),reach_per_eur=VALUES(reach_per_eur),active=VALUES(active);
