-- Preserve existing numeric references and support alphanumeric stable member identifiers.
-- Roll back application code first. Do not narrow back to INT without auditing/archiving string IDs.
ALTER TABLE coop_journal_entry_lines
    MODIFY COLUMN reference_id VARCHAR(255) NULL COMMENT 'Stable identifier of linked record';
