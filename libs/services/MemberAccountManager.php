<?php
require_once __DIR__ . '/../../includes/money_helper.php';
/**
 * MemberAccountManager - Manage Individual Member Accounts (PDO version)
 *
 * Handles member shares, savings, loans, and reconciliation with control accounts.
 * memberid maps to tblemployees.StaffID (INT).
 *
 * @version 2.0
 */
class MemberAccountManager
{
    private PDO $db;
    private static int $savepointNumber = 0;

    private array $CONTROL_ACCOUNTS = [
        'shares'          => 3101,
        'savings'         => 3201,
        'special_savings' => 3202,
        'loan'            => 1110,
    ];

    public function __construct(PDO $db, $database_name = null)
    {
        $this->db = $db;
    }

    /* =================== RECORD TRANSACTION =================== */

    public function recordMemberTransaction(int $memberid, string $account_type, $amount, int $periodid, string $description = ''): array
    {
        return $this->transaction(function () use ($memberid, $account_type, $amount, $periodid) {
            if (!in_array($account_type, ['shares', 'savings', 'special_savings', 'loan', 'dividend', 'interest_payable', 'welfare'], true)) {
                throw new InvalidArgumentException('Invalid member account type');
            }
            $minor = moneyMinorUnits($amount);
            $this->lockPeriod($periodid);
            // StaffID may have historical employee rows; lock all of them across periods.
            $member = $this->execute('SELECT CoopID FROM tblemployees WHERE StaffID = ? ORDER BY CoopID FOR UPDATE', [$memberid])->fetchAll(PDO::FETCH_COLUMN);
            if ($memberid <= 0 || !$member) {
                throw new InvalidArgumentException('Member not found');
            }
            $later = $this->execute('SELECT id FROM coop_member_accounts WHERE memberid = ? AND account_type = ? AND periodid > ? LIMIT 1 FOR UPDATE', [$memberid, $account_type, $periodid])->fetchColumn();
            if ($later !== false) {
                throw new RuntimeException('A later member balance exists; use a current-period correction');
            }
            $existing = $this->execute('SELECT * FROM coop_member_accounts WHERE memberid = ? AND account_type = ? AND periodid = ? FOR UPDATE', [$memberid, $account_type, $periodid])->fetch(PDO::FETCH_ASSOC);
            $opening = $existing ? moneyMinorUnits($existing['opening_balance'])
                : $this->previousBalanceMinor($memberid, $account_type, $periodid, true);
            $credit = moneyAdd($existing ? moneyMinorUnits($existing['credit_amount']) : 0, max(0, $minor));
            $debit = moneyAdd($existing ? moneyMinorUnits($existing['debit_amount']) : 0, max(0, -$minor));
            $closing = moneyAdd(moneyAdd($opening, $credit), -$debit);
            if ($existing) {
                $this->execute('UPDATE coop_member_accounts SET debit_amount = ?, credit_amount = ?, closing_balance = ? WHERE id = ?',
                    [moneyDecimal($debit), moneyDecimal($credit), moneyDecimal($closing), $existing['id']]);
            } else {
                $this->execute('INSERT INTO coop_member_accounts (memberid, account_type, periodid, opening_balance, debit_amount, credit_amount, closing_balance) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$memberid, $account_type, $periodid, moneyDecimal($opening), moneyDecimal($debit), moneyDecimal($credit), moneyDecimal($closing)]);
            }
            return ['success' => true];
        });
    }

    /* =================== GET BALANCE =================== */

    public function getMemberBalance(int $memberid, string $account_type, int $periodid): float
    {
        // Legacy presentation callers expect floats; financial calculations stay in minor units.
        return (float) moneyDecimal($this->balanceMinor($memberid, $account_type, $periodid));
    }

    private function balanceMinor(int $memberid, string $account_type, int $periodid): int
    {
        $row = $this->execute('SELECT closing_balance FROM coop_member_accounts WHERE memberid = ? AND account_type = ? AND periodid = ?',
            [$memberid, $account_type, $periodid])->fetch(PDO::FETCH_ASSOC);
        return $row ? moneyMinorUnits($row['closing_balance']) : $this->previousBalanceMinor($memberid, $account_type, $periodid);
    }

    private function previousBalanceMinor(int $memberid, string $account_type, int $periodid, bool $lock = false): int
    {
        $row = $this->execute('SELECT closing_balance FROM coop_member_accounts WHERE memberid = ? AND account_type = ? AND periodid < ? ORDER BY periodid DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            [$memberid, $account_type, $periodid])->fetch(PDO::FETCH_ASSOC);
        return $row ? moneyMinorUnits($row['closing_balance']) : 0;
    }

    /* =================== GENERATE STATEMENT =================== */

    /**
     * Generate account statement for a member across a range of periods.
     * $memberid = tblemployees.StaffID (int)
     */
    public function generateMemberStatement(int $memberid, int $from_periodid, int $to_periodid): array
    {
        try {
            $member = $this->getMemberDetails($memberid);
            if (!$member) {
                return ['success' => false, 'error' => 'Member not found'];
            }

            $account_types = ['shares', 'savings', 'special_savings', 'loan'];
            $statement     = [];

            foreach ($account_types as $type) {
                $stmt = $this->db->prepare(
                    "SELECT ma.periodid, pp.PayrollPeriod,
                            ma.opening_balance, ma.debit_amount, ma.credit_amount, ma.closing_balance
                     FROM coop_member_accounts ma
                     JOIN tbpayrollperiods pp ON ma.periodid = pp.id
                     WHERE ma.memberid = ? AND ma.account_type = ?
                       AND ma.periodid >= ? AND ma.periodid <= ?
                     ORDER BY ma.periodid"
                );
                $stmt->execute([$memberid, $type, $from_periodid, $to_periodid]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $statement[$type] = $rows;
                }
            }

            return ['success' => true, 'member' => $member, 'statement' => $statement];

        } catch (Throwable $e) {
            error_log("MemberAccountManager::generateMemberStatement - " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /* =================== ACCOUNT SUMMARY =================== */

    public function getMemberAccountSummary(int $memberid, int $periodid): array
    {
        $minor = [];
        foreach (['shares', 'savings', 'special_savings', 'loan'] as $type) {
            $minor[$type] = $this->balanceMinor($memberid, $type, $periodid);
        }
        $minor['total_equity'] = moneyAdd(moneyAdd($minor['shares'], $minor['savings']), $minor['special_savings']);
        $minor['net_position'] = moneyAdd($minor['total_equity'], -$minor['loan']);
        $summary = array_map(fn($value) => (float) moneyDecimal($value), $minor);
        return $summary;
    }

    /* =================== ALL MEMBER BALANCES =================== */

    public function getAllMemberBalances(int $periodid, ?string $account_type = null): array
    {
        // Several records can share a StaffID (a returning member keeps their old
        // record). Join to one, preferring the active record, so a balance row
        // is never listed twice.
        $sql = "SELECT ma.memberid,
                       CONCAT(e.LastName, ', ', e.FirstName, ' ', IFNULL(e.MiddleName,'')) as member_name,
                       ma.account_type, ma.opening_balance, ma.debit_amount,
                       ma.credit_amount, ma.closing_balance
                FROM coop_member_accounts ma
                JOIN tblemployees e ON e.CoopID = (
                    SELECT e2.CoopID FROM tblemployees e2
                    WHERE e2.StaffID = ma.memberid
                    ORDER BY (e2.Status = 'Active') DESC
                    LIMIT 1
                )
                WHERE ma.periodid = ?";

        $params = [$periodid];
        if ($account_type) {
            $sql    .= " AND ma.account_type = ?";
            $params[] = $account_type;
        }
        $sql .= " ORDER BY ma.memberid, ma.account_type";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =================== RECONCILE =================== */

    public function reconcileMemberAccounts(int $periodid): array
    {
        try {
            require_once __DIR__ . '/AccountBalanceCalculator.php';
            $calculator = new AccountBalanceCalculator($this->db);

            $account_types  = ['shares', 'savings', 'special_savings', 'loan'];
            $reconciliation = [];
            $mismatches     = [];

            foreach ($account_types as $type) {
                $stmt = $this->db->prepare(
                    "SELECT SUM(closing_balance) as total FROM coop_member_accounts
                     WHERE account_type = ? AND periodid = ?"
                );
                $stmt->execute([$type, $periodid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $memberMinor = moneyMinorUnits($row['total'] ?? '0.00');
                $code = $this->CONTROL_ACCOUNTS[$type];
                $accountId = $this->execute('SELECT id FROM coop_accounts WHERE account_code = ? AND is_active = TRUE', [(string) $code])->fetchColumn();
                if ($accountId === false) {
                    throw new RuntimeException('Missing active control account: ' . $code);
                }
                $cb = $calculator->getAccountBalance((int) $accountId, $periodid);
                if (isset($cb['error']) || !isset($cb['balance_decimal'])) {
                    throw new RuntimeException($cb['error'] ?? 'An exact control account balance is required');
                }
                $controlMinor = moneyMinorUnits($cb['balance_decimal']);
                $difference = moneyAdd($memberMinor, -$controlMinor);
                $reconciliation[$type] = [
                    'account_type' => $type,
                    'member_total' => (float) moneyDecimal($memberMinor),
                    'control_total' => (float) moneyDecimal($controlMinor),
                    'difference' => (float) moneyDecimal($difference),
                    'matches' => $difference === 0,
                ];
                if ($difference !== 0) $mismatches[] = $reconciliation[$type];
            }

            return [
                'reconciliation' => $reconciliation,
                'mismatches'     => $mismatches,
                'all_match'      => empty($mismatches),
            ];

        } catch (Throwable $e) {
            error_log("MemberAccountManager::reconcileMemberAccounts - " . $e->getMessage());
            return ['reconciliation' => [], 'mismatches' => [], 'all_match' => false, 'error' => $e->getMessage()];
        }
    }

    /* =================== INIT NEW PERIOD =================== */

    public function initializeNewPeriodBalances(int $new_periodid, int $previous_periodid): array
    {
        return $this->transaction(function () use ($new_periodid, $previous_periodid) {
            if ($previous_periodid <= 0 || $new_periodid <= $previous_periodid) {
                throw new InvalidArgumentException('A new period must follow its source period');
            }
            $this->lockPeriod($previous_periodid, false);
            $this->lockPeriod($new_periodid);
            $stmt = $this->execute('INSERT INTO coop_member_accounts
                (memberid, account_type, periodid, opening_balance, debit_amount, credit_amount, closing_balance)
                SELECT memberid, account_type, ?, closing_balance, 0.00, 0.00, closing_balance
                FROM coop_member_accounts AS source WHERE source.periodid = ?
                ON DUPLICATE KEY UPDATE id = coop_member_accounts.id', [$new_periodid, $previous_periodid]);
            return ['success' => true, 'members_initialized' => $stmt->rowCount()];
        });
    }

    /* =================== HELPERS =================== */

    private function execute(string $sql, array $parameters = [])
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt || !$stmt->execute($parameters)) {
            throw new RuntimeException('Member account database operation failed');
        }
        return $stmt;
    }

    private function lockPeriod(int $periodid, bool $requireOpen = true): void
    {
        if ($periodid <= 0 || !$this->execute('SELECT id FROM tbpayrollperiods WHERE id = ? FOR UPDATE', [$periodid])->fetchColumn()) {
            throw new InvalidArgumentException('Accounting period not found');
        }
        if ($requireOpen && $this->execute('SELECT id FROM coop_period_balances WHERE periodid = ? AND is_closed = TRUE LIMIT 1 FOR UPDATE', [$periodid])->fetchColumn()) {
            throw new RuntimeException('Accounting period is closed');
        }
    }

    private function transaction(callable $operation): array
    {
        $owned = !$this->db->inTransaction();
        $savepoint = 'member_accounts_' . ++self::$savepointNumber;
        $started = false;
        try {
            if ($owned) $this->db->beginTransaction();
            else $this->db->exec("SAVEPOINT $savepoint");
            $started = true;
            $result = $operation();
            if ($owned) $this->db->commit();
            else $this->db->exec("RELEASE SAVEPOINT $savepoint");
            return $result;
        } catch (Throwable $error) {
            if ($started && $this->db->inTransaction()) {
                if ($owned) $this->db->rollBack();
                else {
                    $this->db->exec("ROLLBACK TO SAVEPOINT $savepoint");
                    $this->db->exec("RELEASE SAVEPOINT $savepoint");
                }
            }
            error_log('MemberAccountManager: ' . $error->getMessage());
            return ['success' => false, 'error' => $error->getMessage()];
        }
    }

    private function getMemberDetails(int $memberid): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT StaffID AS memberid,
                    CONCAT(LastName, ', ', FirstName, ' ', IFNULL(MiddleName,'')) AS full_name,
                    EmailAddress,
                    MobileNumber AS Phone
             FROM tblemployees
             WHERE StaffID = ?
             ORDER BY (Status = 'Active') DESC
             LIMIT 1"
        );
        $stmt->execute([$memberid]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($member && empty($member['Phone'])) {
            $member['Phone'] = '';
        }
        return $member ?: null;
    }
}
