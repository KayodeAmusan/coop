-- Optional schema rollback only when no keyed command/hash/result evidence exists.
-- Roll application code back first. Never discard a successful reconciliation receipt.
SET @bank_command_rows = 0;
SET @column_exists = EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND COLUMN_NAME='idempotency_key');
SET @bank_recon_ddl = IF(@column_exists, 'SELECT @bank_command_rows := @bank_command_rows + COUNT(*) FROM coop_bank_reconciliation WHERE idempotency_key IS NOT NULL', 'SELECT 1');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
SET @column_exists = EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND COLUMN_NAME='request_hash');
SET @bank_recon_ddl = IF(@column_exists, 'SELECT @bank_command_rows := @bank_command_rows + COUNT(*) FROM coop_bank_reconciliation WHERE request_hash IS NOT NULL', 'SELECT 1');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
SET @column_exists = EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND COLUMN_NAME='result_json');
SET @bank_recon_ddl = IF(@column_exists, 'SELECT @bank_command_rows := @bank_command_rows + COUNT(*) FROM coop_bank_reconciliation WHERE result_json IS NOT NULL', 'SELECT 1');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
SET @index_exists = EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND INDEX_NAME='uq_bank_reconciliation_command');
SET @bank_recon_ddl = IF(@bank_command_rows=0 AND @index_exists, 'ALTER TABLE coop_bank_reconciliation DROP INDEX uq_bank_reconciliation_command', 'SELECT 1');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
SET @column_exists = EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND COLUMN_NAME='idempotency_key');
SET @bank_recon_ddl = IF(@bank_command_rows=0 AND @column_exists, 'ALTER TABLE coop_bank_reconciliation DROP COLUMN idempotency_key', 'SELECT 1');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
SET @column_exists = EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND COLUMN_NAME='request_hash');
SET @bank_recon_ddl = IF(@bank_command_rows=0 AND @column_exists, 'ALTER TABLE coop_bank_reconciliation DROP COLUMN request_hash', 'SELECT 1');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
SET @column_exists = EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND COLUMN_NAME='result_json');
SET @bank_recon_ddl = IF(@bank_command_rows=0 AND @column_exists, 'ALTER TABLE coop_bank_reconciliation DROP COLUMN result_json', 'SELECT 1');
PREPARE bank_recon_stmt FROM @bank_recon_ddl; EXECUTE bank_recon_stmt; DEALLOCATE PREPARE bank_recon_stmt;
SELECT IF(@bank_command_rows=0, 'Empty receipt fields removed; historical rows preserved', 'Successful command receipt fields retained') AS rollback_result;
