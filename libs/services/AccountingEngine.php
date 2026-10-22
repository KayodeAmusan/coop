<?php
require_once __DIR__ . '/../../includes/money_helper.php';

/** Double-entry journal operations preserve the caller's transaction boundary. */
class AccountingEngine {
    private $db;
    private $database_name;
    private static $savepointNumber = 0;

    public function __construct($database_connection, $database_name = null) {
        $this->db = $database_connection;
        $this->database_name = $database_name;
        if ($database_name !== null) {
            if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $database_name)) {
                throw new InvalidArgumentException('Invalid database name');
            }
            $this->db->exec("USE `$database_name`");
        }
    }

    private function execute($sql, array $parameters = []) {
        $statement = $this->db->prepare($sql);
        if (!$statement || !$statement->execute($parameters)) {
            throw new RuntimeException('Accounting database operation failed');
        }
        return $statement;
    }

    private function transaction(callable $operation) {
        $owned = !$this->db->inTransaction();
        $savepoint = 'accounting_' . ++self::$savepointNumber;
        try {
            if ($owned) {
                $this->db->beginTransaction();
            } else {
                $this->db->exec("SAVEPOINT $savepoint");
            }
            $result = $operation();
            if ($owned) {
                $this->db->commit();
            } else {
                $this->db->exec("RELEASE SAVEPOINT $savepoint");
            }
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                if ($owned) {
                    $this->db->rollBack();
                } else {
                    $this->db->exec("ROLLBACK TO SAVEPOINT $savepoint");
                    $this->db->exec("RELEASE SAVEPOINT $savepoint");
                }
            }
            error_log('AccountingEngine: ' . $error->getMessage());
            return ['success' => false, 'error' => $error->getMessage()];
        }
    }

    public function createJournalEntry($periodid, $entry_date, $entry_type, $description, $lines, $created_by, $source_document = null, $idempotency_key = null) {
        return $this->transaction(function () use ($periodid, $entry_date, $entry_type, $description, $lines, $created_by, $source_document, $idempotency_key) {
            $validation = $this->validateJournalLines($lines);
            $request_hash = null;
            if ($idempotency_key !== null) {
                if (!is_string($idempotency_key) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/', $idempotency_key)) {
                    throw new InvalidArgumentException('Idempotency key must contain 1 to 128 ASCII letters, digits, dots, underscores, colons or hyphens');
                }
                $request_hash = $this->journalRequestHash($periodid, $entry_date, $entry_type, $description, $lines, $created_by, $source_document);
                $existing = $this->execute('SELECT * FROM coop_journal_entries WHERE created_by = ? AND idempotency_key = ?', [$created_by, $idempotency_key])->fetch(PDO::FETCH_ASSOC);
                if ($existing) { return $this->replayedJournal($existing, $request_hash); }
            }
            $this->lockPeriod($periodid);
            $entry_number = $this->generateEntryNumber($periodid);
            $columns = 'entry_number, entry_date, periodid, entry_type, source_document, description, total_amount, created_by, status';
            $parameters = [$entry_number, $entry_date, $periodid, $entry_type, $source_document,
                $description, $validation['total_amount'], $created_by, 'draft'];
            if ($idempotency_key !== null) {
                $columns .= ', idempotency_key, request_hash';
                $parameters[] = $idempotency_key;
                $parameters[] = $request_hash;
            }
            try {
                $this->execute('INSERT INTO coop_journal_entries (' . $columns . ') VALUES (' . implode(', ', array_fill(0, count($parameters), '?')) . ')', $parameters);
            } catch (PDOException $error) {
                if ($idempotency_key === null || (string) $error->getCode() !== '23000') { throw $error; }
                // A competing insert may have committed after this transaction's consistent-read snapshot.
                $existing = $this->execute('SELECT * FROM coop_journal_entries WHERE created_by = ? AND idempotency_key = ? FOR UPDATE', [$created_by, $idempotency_key])->fetch(PDO::FETCH_ASSOC);
                if (!$existing) { throw $error; }
                return $this->replayedJournal($existing, $request_hash);
            }
            $entry_id = $this->db->lastInsertId();
            $line_number = 1;
            foreach ($lines as $line) { $this->insertJournalLine($entry_id, $line_number++, $line); }
            return ['success' => true, 'entry_id' => $entry_id, 'entry_number' => $entry_number,
                'total_amount' => $validation['total_amount']];
        });
    }

    private function journalRequestHash($periodid, $entry_date, $entry_type, $description, $lines, $created_by, $source_document) {
        $canonicalLines = [];
        foreach ($lines as $line) {
            $canonicalLines[] = [(string) $line['account_id'], moneyDecimal(moneyMinorUnits($line['debit_amount'] ?? 0)),
                moneyDecimal(moneyMinorUnits($line['credit_amount'] ?? 0)), (string) ($line['description'] ?? ''),
                isset($line['reference_type']) ? (string) $line['reference_type'] : null,
                isset($line['reference_id']) ? (string) $line['reference_id'] : null];
        }
        return hash('sha256', json_encode([(string) $created_by, (string) $periodid, (string) $entry_date,
            (string) $entry_type, (string) $description, $source_document === null ? null : (string) $source_document,
            $canonicalLines], JSON_THROW_ON_ERROR));
    }

    private function replayedJournal(array $entry, $request_hash) {
        if (!is_string($entry['request_hash']) || !hash_equals($entry['request_hash'], $request_hash)) {
            throw new RuntimeException('Idempotency key was already used for a different journal request');
        }
        return ['success' => true, 'entry_id' => $entry['id'], 'entry_number' => $entry['entry_number'],
            'total_amount' => $entry['total_amount'], 'replayed' => true];
    }

    public function postEntry($entry_id) {
        return $this->transaction(function () use ($entry_id) {
            $entry = $this->execute('SELECT * FROM coop_journal_entries WHERE id = ? FOR UPDATE', [$entry_id])->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new RuntimeException('Journal entry not found');
            }
            if ($entry['status'] === 'posted') {
                return ['success' => true];
            }
            if ($entry['status'] !== 'draft' || $entry['is_reversed']) {
                throw new RuntimeException('Only draft entries can be posted');
            }
            $this->lockPeriod($entry['periodid']);
            $lines = $this->execute('SELECT * FROM coop_journal_entry_lines WHERE journal_entry_id = ? ORDER BY account_id, line_number FOR UPDATE', [$entry_id])->fetchAll(PDO::FETCH_ASSOC);
            $validation = $this->validateJournalLines($lines);
            if (moneyMinorUnits($entry['total_amount']) !== moneyMinorUnits($validation['total_amount'])) {
                throw new RuntimeException('Journal header total does not match its lines');
            }
            foreach ($lines as $line) {
                $account = $this->execute('SELECT id, is_active FROM coop_accounts WHERE id = ? FOR UPDATE', [$line['account_id']])->fetch(PDO::FETCH_ASSOC);
                if (!$account || !$account['is_active']) {
                    throw new RuntimeException('Journal account is missing or inactive');
                }
                $this->updatePeriodBalance($entry['periodid'], $line['account_id'], $line['debit_amount'], $line['credit_amount']);
            }
            $this->execute("UPDATE coop_journal_entries SET status = 'posted' WHERE id = ?", [$entry_id]);
            $this->logAuditTrail($entry['created_by'], 'post', 'coop_journal_entries', $entry_id,
                json_encode(['status' => 'draft']), json_encode(['status' => 'posted']));
            return ['success' => true];
        });
    }

    public function reverseEntry($entry_id, $user_id, $reason) {
        return $this->transaction(function () use ($entry_id, $user_id, $reason) {
            $original = $this->execute('SELECT * FROM coop_journal_entries WHERE id = ? FOR UPDATE', [$entry_id])->fetch(PDO::FETCH_ASSOC);
            if (!$original || $original['status'] !== 'posted') {
                throw new RuntimeException('Only posted entries can be reversed');
            }
            if ($original['is_reversed']) {
                $reversal = $this->getJournalEntry($original['reversed_by_entry_id']);
                if (!$reversal || $reversal['status'] !== 'posted') {
                    throw new RuntimeException('Original entry has an invalid reversal link');
                }
                return ['success' => true, 'reversing_entry_id' => $reversal['id'], 'reversing_entry_number' => $reversal['entry_number']];
            }
            $this->lockPeriod($original['periodid']);
            $lines = $this->execute('SELECT * FROM coop_journal_entry_lines WHERE journal_entry_id = ? ORDER BY line_number FOR UPDATE', [$entry_id])->fetchAll(PDO::FETCH_ASSOC);
            foreach ($lines as &$line) {
                $debit = $line['debit_amount'];
                $line['debit_amount'] = $line['credit_amount'];
                $line['credit_amount'] = $debit;
                $line['description'] = 'REVERSAL: ' . $line['description'];
            }
            unset($line);
            $result = $this->createJournalEntry($original['periodid'], date('Y-m-d'), 'adjustment',
                'REVERSAL: ' . $original['description'] . ' - Reason: ' . $reason, $lines, $user_id, 'REV-' . $original['entry_number']);
            if (!$result['success']) {
                throw new RuntimeException($result['error']);
            }
            $posted = $this->postEntry($result['entry_id']);
            if (!$posted['success']) {
                throw new RuntimeException($posted['error']);
            }
            $this->execute('UPDATE coop_journal_entries SET is_reversed = TRUE, reversed_by_entry_id = ? WHERE id = ?', [$result['entry_id'], $entry_id]);
            $this->logAuditTrail($user_id, 'reverse', 'coop_journal_entries', $entry_id,
                json_encode(['is_reversed' => false]), json_encode(['is_reversed' => true, 'reversed_by_entry_id' => $result['entry_id'], 'reason' => $reason]));
            return ['success' => true, 'reversing_entry_id' => $result['entry_id'], 'reversing_entry_number' => $result['entry_number']];
        });
    }

    private function validateJournalLines($lines) {
        if (!is_array($lines) || count($lines) < 2) {
            throw new InvalidArgumentException('Journal entry must have at least two lines');
        }
        $debits = 0;
        $credits = 0;
        foreach ($lines as $line) {
            if (!is_array($line) || !isset($line['account_id']) ||
                !preg_match('/\A[1-9][0-9]*\z/', (string) $line['account_id'])) {
                throw new InvalidArgumentException('A positive account ID is required for every line');
            }
            $debit = moneyMinorUnits($line['debit_amount'] ?? 0);
            $credit = moneyMinorUnits($line['credit_amount'] ?? 0);
            if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                throw new InvalidArgumentException('Each line must have exactly one positive debit or credit');
            }
            $debits = moneyAdd($debits, $debit);
            $credits = moneyAdd($credits, $credit);
        }
        if ($debits !== $credits) {
            throw new InvalidArgumentException('Debits must equal credits exactly');
        }
        return ['valid' => true, 'total_amount' => moneyDecimal($debits)];
    }

    private function insertJournalLine($entry_id, $line_number, $line) {
        $this->execute('INSERT INTO coop_journal_entry_lines
            (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description, reference_type, reference_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                $entry_id, $line_number, $line['account_id'],
                moneyDecimal(moneyMinorUnits($line['debit_amount'] ?? 0)),
                moneyDecimal(moneyMinorUnits($line['credit_amount'] ?? 0)),
                $line['description'] ?? '', $line['reference_type'] ?? null, $line['reference_id'] ?? null
            ]);
    }

    private function lockPeriod($periodid) {
        $period = $this->execute('SELECT id FROM tbpayrollperiods WHERE id = ? FOR UPDATE', [$periodid])->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            throw new RuntimeException('Accounting period not found');
        }
        $closed = $this->execute('SELECT id FROM coop_period_balances WHERE periodid = ? AND is_closed = TRUE LIMIT 1 FOR UPDATE', [$periodid])->fetch(PDO::FETCH_ASSOC);
        if ($closed) {
            throw new RuntimeException('Accounting period is closed');
        }
        // shortcut: earlier-period edits require a controlled rebuild once later openings have been carried forward.
        $later = $this->execute('SELECT id FROM coop_period_balances WHERE periodid > ? AND (opening_debit <> 0 OR opening_credit <> 0) LIMIT 1 FOR UPDATE', [$periodid])->fetchColumn();
        if ($later) {
            throw new RuntimeException('Controlled reconciliation required: later period opening balances already exist');
        }
    }

    private function updatePeriodBalance($periodid, $account_id, $debit_amount, $credit_amount) {
        $this->execute('INSERT INTO coop_period_balances
            (periodid, account_id, opening_debit, opening_credit, period_debit, period_credit, closing_debit, closing_credit)
            VALUES (?, ?, 0, 0, 0, 0, 0, 0)
            ON DUPLICATE KEY UPDATE id = id', [$periodid, $account_id]);
        $balance = $this->execute('SELECT * FROM coop_period_balances WHERE periodid = ? AND account_id = ? FOR UPDATE', [$periodid, $account_id])->fetch(PDO::FETCH_ASSOC);
        if ($balance['is_closed']) {
            throw new RuntimeException('Accounting period is closed');
        }
        $debit = moneyAdd(moneyMinorUnits($balance['period_debit']), moneyMinorUnits($debit_amount));
        $credit = moneyAdd(moneyMinorUnits($balance['period_credit']), moneyMinorUnits($credit_amount));
        $this->execute('UPDATE coop_period_balances SET period_debit = ?, period_credit = ?, closing_debit = ?, closing_credit = ? WHERE id = ?', [
            moneyDecimal($debit), moneyDecimal($credit),
            moneyDecimal(moneyAdd(moneyMinorUnits($balance['opening_debit']), $debit)),
            moneyDecimal(moneyAdd(moneyMinorUnits($balance['opening_credit']), $credit)), $balance['id']
        ]);
    }

    private function generateEntryNumber($periodid) {
        $period = $this->execute('SELECT PayrollPeriod FROM tbpayrollperiods WHERE id = ?', [$periodid])->fetch(PDO::FETCH_ASSOC);
        $year = preg_match('/(\d{4})/', $period['PayrollPeriod'], $matches) ? $matches[1] : date('Y');
        $sequence = 'journal_' . $year;
        $this->execute('INSERT INTO coop_journal_sequences (sequence_name, current_value) VALUES (?, 0) ON DUPLICATE KEY UPDATE current_value = current_value', [$sequence]);
        $row = $this->execute('SELECT current_value FROM coop_journal_sequences WHERE sequence_name = ? FOR UPDATE', [$sequence])->fetch(PDO::FETCH_ASSOC);
        $existing = $this->execute("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)), 0) AS max_num FROM coop_journal_entries WHERE entry_number REGEXP ?", ['^JE-' . $year . '-[0-9]+$'])->fetch(PDO::FETCH_ASSOC);
        $next = max((int) $row['current_value'], (int) $existing['max_num']) + 1;
        if (!is_int($next) || $next < 1) {
            throw new OverflowException('Journal sequence exhausted');
        }
        $this->execute('UPDATE coop_journal_sequences SET current_value = ? WHERE sequence_name = ?', [$next, $sequence]);
        return sprintf('JE-%s-%04d', $year, $next);
    }

    public function getJournalEntry($entry_id) {
        return $this->execute('SELECT * FROM coop_journal_entries WHERE id = ?', [$entry_id])->fetch(PDO::FETCH_ASSOC);
    }

    public function getJournalEntryLines($entry_id) {
        return $this->execute('SELECT jel.*, a.account_code, a.account_name FROM coop_journal_entry_lines jel
            JOIN coop_accounts a ON jel.account_id = a.id WHERE jel.journal_entry_id = ? ORDER BY jel.line_number', [$entry_id])->fetchAll(PDO::FETCH_ASSOC);
    }

    private function logAuditTrail($user_id, $action_type, $table_name, $record_id, $old_values = null, $new_values = null) {
        $this->execute('INSERT INTO coop_audit_trail
            (user_id, action_type, table_name, record_id, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$user_id, $action_type, $table_name, $record_id, $old_values, $new_values,
                $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
    }

    public function getAccount($account_id) {
        return $this->execute('SELECT * FROM coop_accounts WHERE id = ?', [$account_id])->fetch(PDO::FETCH_ASSOC);
    }

    public function getAccountByCode($account_code) {
        return $this->execute('SELECT * FROM coop_accounts WHERE account_code = ?', [$account_code])->fetch(PDO::FETCH_ASSOC);
    }
}
