<?php
require_once __DIR__ . '/../../includes/money_helper.php';
require_once __DIR__ . '/AccountingEngine.php';

class PeriodClosingProcessor {
    private $db;
    private $database_name;
    private static $savepointNumber = 0;

    public function __construct($database_connection, $database_name = null) {
        $this->db = $database_connection;
        $this->database_name = $database_name;
    }

    private function execute($sql, array $parameters = []) {
        $stmt = $this->db->prepare($sql);
        if (!$stmt || !$stmt->execute($parameters)) { throw new RuntimeException('Period closing database operation failed'); }
        return $stmt;
    }

    private function transaction(callable $body) {
        $owned = !$this->db->inTransaction();
        $savepoint = 'closing_' . ++self::$savepointNumber;
        try {
            if ($owned) { $this->db->beginTransaction(); }
            else { $this->db->exec("SAVEPOINT $savepoint"); }
            $result = $body();
            if ($owned) { $this->db->commit(); }
            else { $this->db->exec("RELEASE SAVEPOINT $savepoint"); }
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                if ($owned) { $this->db->rollBack(); }
                else { $this->db->exec("ROLLBACK TO SAVEPOINT $savepoint"); $this->db->exec("RELEASE SAVEPOINT $savepoint"); }
            }
            error_log('PeriodClosingProcessor: ' . $error->getMessage());
            return ['success' => false, 'error' => $error->getMessage()];
        }
    }

    private function periodBalances($periodid, $lock = false) {
        $rows = $this->execute('SELECT * FROM coop_period_balances WHERE periodid = ? ORDER BY account_id' . ($lock ? ' FOR UPDATE' : ''), [$periodid])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $account = $this->execute('SELECT account_type, account_code FROM coop_accounts WHERE id = ?', [$row['account_id']])->fetch(PDO::FETCH_ASSOC);
            if (!$account) { throw new RuntimeException('A period balance has no chart account'); }
            $row += $account;
            $row['net_minor'] = moneyAdd(moneyAdd(moneyMinorUnits($row['opening_debit']), moneyMinorUnits($row['period_debit'])),
                -moneyAdd(moneyMinorUnits($row['opening_credit']), moneyMinorUnits($row['period_credit'])));
        }
        unset($row);
        return $rows;
    }

    private function validation($periodid, array $balances) {
        $issues = [];
        $warnings = [];
        // shortcut: closure is recorded on balance rows; reject empty periods until period-level status exists.
        if (!$balances) { $issues[] = 'An empty period has no balance records to lock closed'; }
        $difference = 0;
        foreach ($balances as $balance) {
            if ($balance['is_closed']) { $issues[] = 'Period is already closed'; break; }
            $difference = moneyAdd($difference, $balance['net_minor']);
        }
        if ($difference !== 0) { $issues[] = 'Trial balance is out of balance by ' . moneyDecimal($difference); }
        $drafts = $this->execute("SELECT COUNT(*) FROM coop_journal_entries WHERE periodid = ? AND status = 'draft'", [$periodid])->fetchColumn();
        if ($drafts > 0) { $warnings[] = $drafts . ' draft journal entries will not be included'; }
        return ['can_close' => !$issues, 'issues' => array_unique($issues), 'warnings' => $warnings];
    }

    public function validatePeriodForClosing($periodid) {
        return $this->validation($periodid, $this->periodBalances($periodid));
    }

    private function lockPeriod($periodid) {
        if (!$this->execute('SELECT id FROM tbpayrollperiods WHERE id = ? FOR UPDATE', [$periodid])->fetchColumn()) {
            throw new RuntimeException('Accounting period not found');
        }
    }

    public function closePeriod($periodid, $user_id, $appropriation_data = []) {
        return $this->transaction(function () use ($periodid, $user_id, $appropriation_data) {
            $this->lockPeriod($periodid);
            $balances = $this->periodBalances($periodid, true);
            $validation = $this->validation($periodid, $balances);
            if (!$validation['can_close']) { throw new RuntimeException(implode('; ', $validation['issues'])); }
            $entries = [];
            $surplus = 0;
            $lines = [];
            foreach ($balances as $balance) {
                if (!in_array($balance['account_type'], ['revenue', 'expense'], true) || $balance['net_minor'] === 0) { continue; }
                $net = $balance['net_minor'];
                $surplus = moneyAdd($surplus, -$net);
                $lines[] = ['account_id' => $balance['account_id'], 'debit_amount' => moneyDecimal(max(0, -$net)),
                    'credit_amount' => moneyDecimal(max(0, $net)), 'description' => 'Close ' . $balance['account_code']];
            }
            if ($lines) {
                if ($surplus !== 0) {
                    $lines[] = ['account_id' => $this->accountId('3401'), 'debit_amount' => moneyDecimal(max(0, -$surplus)),
                        'credit_amount' => moneyDecimal(max(0, $surplus)), 'description' => 'Transfer period result to retained earnings'];
                }
                $entries[] = $this->postJournal($periodid, $user_id, 'closing', 'Close revenue and expense accounts', $lines, 'CLOSING-' . $periodid)['entry_number'];
            }
            if ($appropriation_data) {
                $entry = $this->processAppropriation($periodid, $user_id, $appropriation_data, $surplus);
                if ($entry) { $entries[] = $entry; }
            }
            $this->execute('UPDATE coop_period_balances SET is_closed = TRUE, closed_at = NOW(), closed_by = ? WHERE periodid = ?', [$user_id, $periodid]);
            $this->createOpeningBalancesForNextPeriod($periodid);
            $this->audit($periodid, $user_id, 'close_period', 'Period closed');
            return ['success' => true, 'entries_created' => $entries, 'message' => 'Period closed successfully'];
        });
    }

    private function accountId($code) {
        $id = $this->execute('SELECT id FROM coop_accounts WHERE account_code = ? AND is_active = TRUE', [$code])->fetchColumn();
        if (!$id) { throw new RuntimeException('Required active chart account missing: ' . $code); }
        return (int) $id;
    }

    private function postJournal($periodid, $user_id, $type, $description, $lines, $source) {
        $engine = new AccountingEngine($this->db);
        $result = $engine->createJournalEntry($periodid, date('Y-m-d'), $type, $description, $lines, $user_id, $source);
        if (!$result['success']) { throw new RuntimeException($result['error']); }
        $posted = $engine->postEntry($result['entry_id']);
        if (!$posted['success']) { throw new RuntimeException($posted['error']); }
        return $result;
    }

    private function processAppropriation($periodid, $user_id, array $data, int $surplus) {
        $destinations = ['dividend' => '2103', 'interest_to_members' => '2104', 'reserve_fund' => '3301',
            'bonus' => '2105', 'education_fund' => '3303', 'honorarium' => '2106', 'general_reserve' => '3302', 'welfare_fund' => '3304'];
        $claimedSurplus = moneyMinorUnits($data['surplus_amount'] ?? 0);
        if ($claimedSurplus !== $surplus) { throw new InvalidArgumentException('Appropriation surplus must match the period result'); }
        $total = 0;
        $amounts = [];
        $lines = [];
        foreach ($destinations as $key => $code) {
            $amount = moneyMinorUnits($data[$key] ?? 0);
            if ($amount < 0) { throw new InvalidArgumentException('Appropriation allocations cannot be negative'); }
            $total = moneyAdd($total, $amount);
            $amounts[] = moneyDecimal($amount);
            if ($amount > 0) {
                $lines[] = ['account_id' => $this->accountId($code), 'debit_amount' => '0.00',
                    'credit_amount' => moneyDecimal($amount), 'description' => str_replace('_', ' ', $key)];
            }
        }
        if ($total > max(0, $surplus)) { throw new InvalidArgumentException('Appropriations exceed the period surplus'); }
        $retained = moneyAdd($surplus, -$total);
        if (isset($data['retained_earnings']) && moneyMinorUnits($data['retained_earnings']) !== $retained) {
            throw new InvalidArgumentException('Retained earnings do not match surplus less appropriations');
        }
        $entry = null;
        if ($lines) {
            $lines[] = ['account_id' => $this->accountId('3401'), 'debit_amount' => moneyDecimal($total),
                'credit_amount' => '0.00', 'description' => 'Distribute period surplus'];
            $entry = $this->postJournal($periodid, $user_id, 'appropriation', 'Surplus appropriation for period', $lines, 'APPROP-' . $periodid);
        }
        $this->execute('INSERT INTO coop_appropriation
            (periodid, surplus_amount, dividend_amount, interest_to_members, reserve_fund, bonus_amount, education_fund,
             honorarium, general_reserve, welfare_fund, retained_earnings, journal_entry_id, is_posted, approved_by, approval_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?, CURRENT_DATE)',
            array_merge([$periodid, moneyDecimal($surplus)], $amounts, [moneyDecimal($retained), $entry['entry_id'] ?? null, $user_id]));
        return $entry['entry_number'] ?? null;
    }

    private function createOpeningBalancesForNextPeriod($periodid) {
        $next = $this->execute('SELECT id FROM tbpayrollperiods WHERE id > ? ORDER BY id LIMIT 1', [$periodid])->fetchColumn();
        if (!$next) { return; }
        $this->lockPeriod($next);
        $closed = $this->execute('SELECT id FROM coop_period_balances WHERE periodid = ? AND is_closed = TRUE LIMIT 1 FOR UPDATE', [$next])->fetchColumn();
        if ($closed) { throw new RuntimeException('Cannot change opening balances of a closed next period'); }
        $this->execute('INSERT INTO coop_period_balances
            (periodid, account_id, opening_debit, opening_credit, closing_debit, closing_credit)
            SELECT ?, source.account_id, source.closing_debit, source.closing_credit, source.closing_debit, source.closing_credit FROM coop_period_balances AS source WHERE source.periodid = ?
            ON DUPLICATE KEY UPDATE opening_debit = VALUES(opening_debit), opening_credit = VALUES(opening_credit),
                closing_debit = VALUES(opening_debit) + coop_period_balances.period_debit, closing_credit = VALUES(opening_credit) + coop_period_balances.period_credit', [$next, $periodid]);
        require_once __DIR__ . '/MemberAccountManager.php';
        $result = (new MemberAccountManager($this->db))->initializeNewPeriodBalances((int) $next, (int) $periodid);
        if (!$result['success']) { throw new RuntimeException($result['error']); }
    }

    private function audit($periodid, $user_id, $action, $reason) {
        $this->execute('INSERT INTO coop_audit_trail (user_id, action_type, table_name, record_id, notes) VALUES (?, ?, ?, ?, ?)',
            [$user_id, $action, 'coop_period_balances', $periodid, $reason]);
    }

    public function reopenPeriod($periodid, $user_id, $reason) {
        return $this->transaction(function () use ($periodid, $user_id, $reason) {
            $this->lockPeriod($periodid);
            $balances = $this->periodBalances($periodid, true);
            if (!array_filter($balances, function ($balance) { return (bool) $balance['is_closed']; })) {
                throw new RuntimeException('Accounting period is not closed');
            }
            $closingJournal = $this->execute("SELECT id FROM coop_journal_entries WHERE periodid = ? AND entry_type IN ('closing', 'appropriation') AND status = 'posted' AND is_reversed = FALSE LIMIT 1", [$periodid])->fetchColumn();
            $laterOpening = $this->execute('SELECT id FROM coop_period_balances WHERE periodid > ? AND (opening_debit <> 0 OR opening_credit <> 0) LIMIT 1', [$periodid])->fetchColumn();
            if ($closingJournal || $laterOpening) {
                // shortcut: automated reversal and downstream opening rebuild require a controlled reopening workflow.
                throw new RuntimeException('Controlled reopening/reconciliation required: closing journals or later opening balances exist');
            }
            if (trim((string) $reason) === '') { throw new InvalidArgumentException('A reopening reason is required'); }
            $this->execute('UPDATE coop_period_balances SET is_closed = FALSE, closed_at = NULL, closed_by = NULL WHERE periodid = ?', [$periodid]);
            $this->audit($periodid, $user_id, 'reopen_period', $reason);
            return ['success' => true];
        });
    }

    public function getPeriodStatus($periodid) {
        $row = $this->execute('SELECT is_closed, closed_at, closed_by FROM coop_period_balances WHERE periodid = ? ORDER BY is_closed DESC LIMIT 1', [$periodid])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return ['is_closed' => false, 'status' => 'open']; }
        $row['is_closed'] = (bool) $row['is_closed'];
        $row['status'] = $row['is_closed'] ? 'closed' : 'open';
        return $row;
    }
}
