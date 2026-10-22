-- Apply before deploying AccountingEngine. Existing monetary records are unchanged.
-- The engine initializes each year's counter while holding its sequence row lock.
CREATE TABLE IF NOT EXISTS coop_journal_sequences (
    sequence_name VARCHAR(32) NOT NULL PRIMARY KEY,
    current_value BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
