-- Apply in staging after backing up loan_requests and guarantor_requests.
-- Existing responses are deliberately not marked as authenticated consent.
SET @has_actor = (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guarantor_requests' AND COLUMN_NAME = 'responded_by_coop_id');
SET @sql = IF(@has_actor = 0,
 'ALTER TABLE guarantor_requests ADD COLUMN responded_by_coop_id VARCHAR(255) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_hash = (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guarantor_requests' AND COLUMN_NAME = 'consent_terms_hash');
SET @sql = IF(@has_hash = 0,
 'ALTER TABLE guarantor_requests ADD COLUMN consent_terms_hash CHAR(64) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS loan_guarantor_response_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 guarantor_request_id INT NOT NULL,
 guarantor_coop_id VARCHAR(255) NOT NULL,
 response VARCHAR(20) NOT NULL,
 responded_by_coop_id VARCHAR(255) NULL,
 consent_terms_hash CHAR(64) NULL,
 terms_snapshot TEXT NOT NULL,
 response_date DATETIME NULL,
 response_notes TEXT NULL,
 recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_guarantor_response_history (guarantor_request_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- No financial balances, existing decisions or member records are rewritten.
-- Roll back application code before removing these additive objects; retain history.
