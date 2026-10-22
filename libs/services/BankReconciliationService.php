<?php
require_once __DIR__ . '/../../includes/money_helper.php';
require_once __DIR__ . '/AccountingEngine.php';

class BankReconciliationService {
    private $db;
    private $database_name;
    private static $savepointNumber = 0;

    public function __construct($database_connection, $database_name = null) {
        $this->db = $database_connection;
        $this->database_name = $database_name;
    }

    private function execute($sql, array $parameters = []) {
        $stmt = $this->db->prepare($sql);
        if (!$stmt || !$stmt->execute($parameters)) { throw new RuntimeException('Bank reconciliation database operation failed'); }
        return $stmt;
    }

    public function createReconciliation($data) {
        $owned = !$this->db->inTransaction();
        $savepoint = 'bank_reconciliation_' . ++self::$savepointNumber;
        $started = false;
        try {
            $command = $this->validatedCommand($data);
            $hash = hash('sha256', json_encode($command['payload'], JSON_THROW_ON_ERROR));
            if ($owned) { $this->db->beginTransaction(); }
            else { $this->db->exec("SAVEPOINT $savepoint"); }
            $started = true;
            $periodid = $command['payload']['periodid'];
            $bankId = $command['payload']['bank_account_id'];
            $actor = $command['payload']['reconciled_by'];
            $key = $command['key'];
            $existing = $this->execute('SELECT * FROM coop_bank_reconciliation WHERE reconciled_by = ? AND idempotency_key = ?', [$actor, $key])->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $result = $this->replayedResult($existing, $hash);
            } else {
                if (!$this->execute('SELECT id FROM tbpayrollperiods WHERE id = ? FOR UPDATE', [$periodid])->fetchColumn()) throw new InvalidArgumentException('Accounting period not found', 400);
                // Current read after the period lock catches retries that initially missed another uncommitted command.
                $existing = $this->execute('SELECT * FROM coop_bank_reconciliation WHERE reconciled_by = ? AND idempotency_key = ? FOR UPDATE', [$actor, $key])->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $result = $this->replayedResult($existing, $hash);
                } else {
                    $result = $this->createValidatedReconciliation($command['payload'], $key, $hash);
                }
            }
            if ($owned) { $this->db->commit(); }
            else { $this->db->exec("RELEASE SAVEPOINT $savepoint"); }
            return $result;
        } catch (Throwable $error) {
            if ($started && $this->db->inTransaction()) {
                if ($owned) { $this->db->rollBack(); }
                else { $this->db->exec("ROLLBACK TO SAVEPOINT $savepoint"); $this->db->exec("RELEASE SAVEPOINT $savepoint"); }
            }
            error_log('BankReconciliationService: ' . $error->getMessage());
            return ['success' => false, 'error' => $error->getMessage(),
                'error_code' => $error instanceof InvalidArgumentException || $error instanceof OverflowException ? 400 : ($error->getCode() === 409 ? 409 : 500)];
        }
    }

    private function validatedCommand($data): array {
        if (!is_array($data)) throw new InvalidArgumentException('A reconciliation command is required', 400);
        $required = ['periodid','bank_account_id','reconciliation_date','bank_statement_balance','book_balance','outstanding_deposits','outstanding_withdrawals','bank_charges','bank_interest','reconciled_by','notes','idempotency_key'];
        if (array_diff($required, array_keys($data)) || array_diff(array_keys($data), $required)) throw new InvalidArgumentException('Provide all reconciliation fields and a submission reference; unknown fields are not supported', 400);
        $payload = [];
        foreach (['periodid','bank_account_id','reconciled_by'] as $field) {
            $value = $data[$field];
            if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $value) || (int) $value > 2147483647) throw new InvalidArgumentException('Valid period, bank account and authenticated staff identifiers are required', 400);
            $payload[$field] = (int) $value;
        }
        $date = $data['reconciliation_date'];
        if (!is_string($date) || !preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) || (int) $parts[1] < 1000 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) throw new InvalidArgumentException('A valid explicit reconciliation date is required', 400);
        $payload['reconciliation_date'] = $date;
        foreach (['bank_statement_balance','book_balance','outstanding_deposits','outstanding_withdrawals','bank_charges','bank_interest'] as $field) {
            $minor = moneyMinorUnits($data[$field]);
            if (!in_array($field, ['bank_statement_balance','book_balance'], true) && $minor < 0) throw new InvalidArgumentException('Reconciliation adjustments cannot be negative', 400);
            $payload[$field] = moneyDecimal($minor);
        }
        if (!is_string($data['notes']) || strlen($data['notes']) > 65535) throw new InvalidArgumentException('Reconciliation notes must be text within 65535 bytes', 400);
        $payload['notes'] = trim($data['notes']);
        if (!is_string($data['idempotency_key']) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/', $data['idempotency_key'])) throw new InvalidArgumentException('A valid submission reference is required', 400);
        return ['payload' => $payload, 'key' => $data['idempotency_key']];
    }

    private function replayedResult(array $existing, string $hash): array {
        if (!is_string($existing['request_hash']) || !hash_equals($existing['request_hash'], $hash)) throw new RuntimeException('Submission reference was already used with different reconciliation inputs. Start a new reconciliation to change them.', 409);
        if (empty($existing['result_json'])) throw new RuntimeException('The original reconciliation result needs administrator review', 409);
        $result = json_decode($existing['result_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || (int) ($result['reconciliation_id'] ?? 0) !== (int) $existing['id'] || !isset($result['adjusting_entry_ids'])) throw new RuntimeException('Stored reconciliation result failed its integrity check');
        $result['replayed'] = true;
        return $result;
    }

    private function createValidatedReconciliation(array $data, string $key, string $hash): array {
        $periodid = $data['periodid']; $bankId = $data['bank_account_id']; $actor = $data['reconciled_by'];
        if ($this->execute('SELECT id FROM coop_period_balances WHERE periodid = ? AND is_closed = TRUE LIMIT 1 FOR UPDATE', [$periodid])->fetchColumn()) throw new RuntimeException('Accounting period is closed');
        $bank = $this->execute('SELECT id, normal_balance FROM coop_accounts WHERE id = ? AND is_active = TRUE FOR UPDATE', [$bankId])->fetch(PDO::FETCH_ASSOC);
        if (!$bank) throw new InvalidArgumentException('An active bank account is required', 400);
        $amounts = [];
        foreach (['bank_statement_balance','book_balance','outstanding_deposits','outstanding_withdrawals','bank_charges','bank_interest'] as $field) $amounts[$field] = moneyMinorUnits($data[$field]);
        $balance = $this->execute('SELECT opening_debit, opening_credit, period_debit, period_credit FROM coop_period_balances WHERE periodid = ? AND account_id = ? FOR UPDATE', [$periodid, $bankId])->fetch(PDO::FETCH_ASSOC);
        $book = $balance ? moneyAdd(moneyAdd(moneyMinorUnits($balance['opening_debit']), moneyMinorUnits($balance['period_debit'])), -moneyAdd(moneyMinorUnits($balance['opening_credit']), moneyMinorUnits($balance['period_credit']))) : 0;
        if ($bank['normal_balance'] === 'credit') $book = -$book;
        if ($amounts['book_balance'] !== $book) throw new InvalidArgumentException('Book balance has changed; refresh the reconciliation', 400);
        $adjustedBank = moneyAdd(moneyAdd($amounts['bank_statement_balance'], $amounts['outstanding_deposits']), -$amounts['outstanding_withdrawals']);
        $adjustedBook = moneyAdd(moneyAdd($book, $amounts['bank_interest']), -$amounts['bank_charges']);
        $variance = moneyAdd($adjustedBank, -$adjustedBook);
        try {
            $this->execute('INSERT INTO coop_bank_reconciliation
                (periodid, bank_account_id, reconciliation_date, bank_statement_balance, book_balance, outstanding_deposits,
                 outstanding_withdrawals, bank_charges, bank_interest, reconciled_balance, is_balanced, variance, reconciled_by, notes, idempotency_key, request_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge(
                    [$periodid, $bankId, $data['reconciliation_date']], array_map('moneyDecimal', array_values($amounts)),
                    [moneyDecimal($adjustedBank), $variance === 0 ? 1 : 0, moneyDecimal($variance), $actor, $data['notes'], $key, $hash]));
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) throw $error;
            $existing = $this->execute('SELECT * FROM coop_bank_reconciliation WHERE reconciled_by = ? AND idempotency_key = ? FOR UPDATE', [$actor, $key])->fetch(PDO::FETCH_ASSOC);
            if (!$existing) throw $error;
            return $this->replayedResult($existing, $hash);
        }
        $id = (int) $this->db->lastInsertId();
        $entryIds = $this->createAdjustingEntries($periodid, $bankId, $amounts['bank_charges'], $amounts['bank_interest'], $actor, $id, $data['reconciliation_date']);
        $result = ['success' => true, 'reconciliation_id' => $id, 'is_balanced' => $variance === 0,
            'variance' => moneyDecimal($variance), 'adjusted_bank_balance' => moneyDecimal($adjustedBank), 'adjusted_book_balance' => moneyDecimal($adjustedBook),
            'adjusting_entries' => count($entryIds) > 0, 'adjusting_entry_ids' => $entryIds, 'replayed' => false];
        $this->execute("INSERT INTO coop_audit_trail (user_id, action_type, table_name, record_id, new_values) VALUES (?, 'create', 'coop_bank_reconciliation', ?, ?)", [$actor, $id, json_encode(['request_hash' => $hash, 'periodid' => $periodid, 'bank_account_id' => $bankId, 'journal_ids' => $entryIds], JSON_THROW_ON_ERROR)]);
        $this->execute('UPDATE coop_bank_reconciliation SET result_json = ? WHERE id = ?', [json_encode($result, JSON_THROW_ON_ERROR), $id]);
        return $result;
    }

    private function createAdjustingEntries($periodid, $bankId, int $charges, int $interest, $userId, $reconciliationId, string $entryDate): array {
        $entryIds = [];
        $engine = new AccountingEngine($this->db);
        foreach ([['CHARGES', $charges, '6009'], ['INTEREST', $interest, '4299']] as [$kind, $amount, $code]) {
            if ($amount === 0) { continue; }
            $account = $this->execute('SELECT id FROM coop_accounts WHERE account_code = ? AND is_active = TRUE', [$code])->fetchColumn();
            if (!$account) { throw new RuntimeException('Required active chart account missing: ' . $code); }
            $debit = $kind === 'CHARGES' ? $account : $bankId;
            $credit = $kind === 'CHARGES' ? $bankId : $account;
            $lines = [['account_id' => $debit, 'debit_amount' => moneyDecimal($amount), 'credit_amount' => '0.00'],
                ['account_id' => $credit, 'debit_amount' => '0.00', 'credit_amount' => moneyDecimal($amount)]];
            $result = $engine->createJournalEntry($periodid, $entryDate, 'adjustment', 'Bank reconciliation ' . strtolower($kind),
                $lines, $userId, 'BANK-RECON-' . $reconciliationId . '-' . $kind, 'bank-recon:' . $reconciliationId . ':' . strtolower($kind));
            if (!$result['success']) { throw new RuntimeException($result['error']); }
            $posted = $engine->postEntry($result['entry_id']);
            if (!$posted['success']) { throw new RuntimeException($posted['error']); }
            $entryIds[$kind] = (int) $result['entry_id'];
        }
        return $entryIds;
    }

    public function getReconciliationHistory($bank_account_id = null, $limit = 10) {
        $sql = 'SELECT br.*, a.account_code, a.account_name, pp.PayrollPeriod FROM coop_bank_reconciliation br
            JOIN coop_accounts a ON br.bank_account_id = a.id LEFT JOIN tbpayrollperiods pp ON br.periodid = pp.id';
        $parameters = [];
        if ($bank_account_id) { $sql .= ' WHERE br.bank_account_id = ?'; $parameters[] = $bank_account_id; }
        $sql .= ' ORDER BY br.reconciliation_date DESC, br.id DESC LIMIT ' . max(0, (int) $limit);
        return $this->execute($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBookBalance($bank_account_id, $periodid) {
        require_once __DIR__ . '/AccountBalanceCalculator.php';
        $balance = (new AccountBalanceCalculator($this->db, $this->database_name))->getAccountBalance($bank_account_id, $periodid);
        if (isset($balance['error'])) { throw new RuntimeException($balance['error']); }
        return $balance['balance'];
    }
}
