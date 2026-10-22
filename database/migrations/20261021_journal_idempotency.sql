-- Nullable request identity preserves historical journals and permits legitimate repeated source documents.
-- The request key is scoped to its authenticated creator; canonical payload hash detects conflicting retries.
SET @coop_ledger_idempotency_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coop_journal_entries' AND COLUMN_NAME = 'idempotency_key'),
    'SELECT 1',
    'ALTER TABLE coop_journal_entries ADD COLUMN idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL');
PREPARE coop_ledger_idempotency_stmt FROM @coop_ledger_idempotency_sql;
EXECUTE coop_ledger_idempotency_stmt;
DEALLOCATE PREPARE coop_ledger_idempotency_stmt;
SET @coop_ledger_idempotency_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coop_journal_entries' AND COLUMN_NAME = 'request_hash'),
    'SELECT 1',
    'ALTER TABLE coop_journal_entries ADD COLUMN request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL');
PREPARE coop_ledger_idempotency_stmt FROM @coop_ledger_idempotency_sql;
EXECUTE coop_ledger_idempotency_stmt;
DEALLOCATE PREPARE coop_ledger_idempotency_stmt;
SET @coop_ledger_idempotency_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coop_journal_entries' AND INDEX_NAME = 'unique_creator_idempotency'),
    'SELECT 1',
    'ALTER TABLE coop_journal_entries ADD UNIQUE KEY unique_creator_idempotency (created_by, idempotency_key)');
PREPARE coop_ledger_idempotency_stmt FROM @coop_ledger_idempotency_sql;
EXECUTE coop_ledger_idempotency_stmt;
DEALLOCATE PREPARE coop_ledger_idempotency_stmt;
