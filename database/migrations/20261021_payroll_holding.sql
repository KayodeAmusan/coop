-- Additive, replay-safe payroll holding setup. No account or default selection is inserted.
-- Existing allocations are untouched. Preserve these tables during rollback while funds remain held.
CREATE TABLE IF NOT EXISTS coop_payroll_configuration (
    id TINYINT NOT NULL PRIMARY KEY,
    holding_account_id INT NOT NULL,
    configured_by INT NOT NULL,
    configured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_payroll_configuration_singleton CHECK (id = 1),
    CONSTRAINT fk_payroll_holding_account FOREIGN KEY (holding_account_id) REFERENCES coop_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coop_payroll_unallocated (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_type VARCHAR(30) NOT NULL,
    source_key VARCHAR(191) NOT NULL,
    coop_id VARCHAR(255) NOT NULL,
    period_id INT NOT NULL,
    reason VARCHAR(50) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    holding_account_id INT NOT NULL,
    journal_entry_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_payroll_unallocated_positive CHECK (amount > 0),
    UNIQUE KEY uq_payroll_unallocated_source (source_type, source_key, coop_id, period_id),
    INDEX idx_payroll_unallocated_period (period_id, coop_id),
    INDEX idx_payroll_unallocated_journal (journal_entry_id),
    CONSTRAINT fk_payroll_unallocated_account FOREIGN KEY (holding_account_id) REFERENCES coop_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payroll_unallocated_journal FOREIGN KEY (journal_entry_id) REFERENCES coop_journal_entries(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
