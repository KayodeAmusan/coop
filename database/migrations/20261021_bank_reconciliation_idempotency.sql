-- Stable actor-scoped reconciliation command identity, payload fingerprint and original result.
-- Historical records retain NULL identity and are never backfilled or reposted.
SET @bank_recon_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND COLUMN_NAME='idempotency_key'), 'SELECT 1', 'ALTER TABLE coop_bank_reconciliation ADD COLUMN idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
SET @bank_recon_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND COLUMN_NAME='request_hash'), 'SELECT 1', 'ALTER TABLE coop_bank_reconciliation ADD COLUMN request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
SET @bank_recon_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND COLUMN_NAME='result_json'), 'SELECT 1', 'ALTER TABLE coop_bank_reconciliation ADD COLUMN result_json JSON NULL');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
SET @bank_recon_ddl = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND INDEX_NAME='uq_bank_reconciliation_command'), 'SELECT 1', 'ALTER TABLE coop_bank_reconciliation ADD UNIQUE KEY uq_bank_reconciliation_command (reconciled_by, idempotency_key)');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
-- Roll back code first; preserve nonnull command/result records for retry and audit evidence.
