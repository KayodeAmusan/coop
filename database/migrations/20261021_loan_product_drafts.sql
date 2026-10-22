-- Additive draft storage only; does not configure or enable any loan product.
-- No existing loan is assigned terms or rewritten. Apply in isolated staging first.
CREATE TABLE IF NOT EXISTS coop_loan_products (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 product_code VARCHAR(40) NOT NULL,
 name VARCHAR(150) NOT NULL,
 description TEXT NOT NULL,
 status ENUM('draft', 'inactive') NOT NULL DEFAULT 'draft',
 created_by_user_id INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_loan_product_code (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coop_loan_product_versions (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 product_id INT NOT NULL,
 version_number INT NOT NULL,
 configuration_json JSON NOT NULL,
 terms_text TEXT NULL,
 content_hash CHAR(64) NOT NULL,
 created_by_user_id INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_loan_product_version (product_id, version_number),
 CONSTRAINT fk_loan_product_version_product FOREIGN KEY (product_id) REFERENCES coop_loan_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nullable application binding reserved for the future supported application flow.
SET @has_loan_product_version = (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='loan_requests' AND COLUMN_NAME='loan_product_version_id');
SET @ddl = IF(@has_loan_product_version=0, 'ALTER TABLE loan_requests ADD COLUMN loan_product_version_id INT NULL, ADD INDEX idx_loan_product_version (loan_product_version_id)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_product_snapshot = (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='loan_requests' AND COLUMN_NAME='product_terms_snapshot');
SET @ddl = IF(@has_product_snapshot=0, 'ALTER TABLE loan_requests ADD COLUMN product_terms_snapshot LONGTEXT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_product_hash = (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='loan_requests' AND COLUMN_NAME='product_terms_hash');
SET @ddl = IF(@has_product_hash=0, 'ALTER TABLE loan_requests ADD COLUMN product_terms_hash CHAR(64) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
