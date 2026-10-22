<?php
/** Isolated status/cap regression tests; SQLite does not verify MySQL locking. */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/loan_consent_helper.php';
require_once __DIR__ . '/loan_evidence_fixture.php';
$source = file_get_contents(__DIR__ . '/../auth_api/api/loans/guarantor-request.php');
$start = strpos($source, 'function updateLoanRequestStatus(');
$end = strpos($source, 'function sendGuarantorNotification(', $start);
eval(substr($source, $start, $end - $start));

class GuarantorStatusDatabase
{
    public $pdo;
    public $queries = [];
    public function __construct()
    {
        if (getenv('COOP_GUARANTOR_MYSQL_TEST') === '1') {
            // Fixed isolated local target; never use application configuration here.
            $this->pdo = new PDO('mysql:host=127.0.0.1;port=13307;charset=utf8mb4', 'root', '');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('CREATE DATABASE IF NOT EXISTS coop_test_guarantors');
            $this->pdo->exec('USE coop_test_guarantors');
            foreach (['loan_guarantor_evidence', 'guarantor_requests', 'loan_requests', 'loan_period_limits', 'tblemployees'] as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
            }
        } else {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->sqliteCreateFunction('NOW', fn() => '2026-10-21 12:00:00');
        }
        $this->pdo->exec('CREATE TABLE loan_requests (id INTEGER PRIMARY KEY, requested_amount DECIMAL(15,2), monthly_repayment DECIMAL(15,2), period_id INTEGER, requester_coop_id TEXT, status TEXT, updated_at TEXT, submitted_at TEXT)');
        $this->pdo->exec('CREATE TABLE guarantor_requests (id INTEGER PRIMARY KEY, loan_request_id INTEGER, guarantor_coop_id VARCHAR(255), requested_amount DECIMAL(15,2), monthly_repayment DECIMAL(15,2), status TEXT)');
        $this->pdo->exec('CREATE TABLE loan_period_limits (period_id INTEGER PRIMARY KEY, limit_amount DECIMAL(15,2))');
        $this->pdo->exec("INSERT INTO loan_requests VALUES (1, '0.20', '0.02', 10, 'borrower', 'partially_guaranteed', NULL, NULL), (2, '0.10', '0.01', 10, 'other', 'approved', NULL, NULL)");
        $this->pdo->exec("INSERT INTO guarantor_requests VALUES (1, 1, 'g1', '0.20', '0.02', 'approved'), (2, 1, 'g2', '0.20', '0.02', 'approved')");
        $this->pdo->exec('CREATE TABLE tblemployees (CoopID VARCHAR(255) PRIMARY KEY, Status VARCHAR(20))');
        $this->pdo->exec("INSERT INTO tblemployees VALUES ('g1','Active'),('g2','Active')");
        createTestLoanEvidenceTable($this->pdo);
        $hash = loanConsentTermsHash(['id'=>1,'requester_coop_id'=>'borrower','requested_amount'=>'0.20','monthly_repayment'=>'0.02']);
        seedTestLoanEvidence($this->pdo, 1, 1, 'g1', $hash);
        seedTestLoanEvidence($this->pdo, 1, 2, 'g2', $hash);
    }
    public function inTransaction() { return $this->pdo->inTransaction(); }
    public function prepare($sql)
    {
        $this->queries[] = $sql;
        return $this->pdo->prepare($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? preg_replace('/\s+FOR UPDATE\s*$/i', '', $sql) : $sql);
    }
}
function sendLimitExceededNotification(...$args) { throw new RuntimeException('Notification sent before commit'); }

$passed = 0;
foreach (['0.30' => 'submitted', '0.29' => 'rejected'] as $limit => $expected) {
    $db = new GuarantorStatusDatabase();
    $db->pdo->prepare('INSERT INTO loan_period_limits VALUES (10, ?)')->execute([$limit]);
    $db->pdo->beginTransaction();
    $result = updateLoanRequestStatus($db, 1);
    assertSameValue($expected, $result['status']);
    assertSameValue($expected, $db->pdo->query('SELECT status FROM loan_requests WHERE id = 1')->fetchColumn());
    assertSameValue($expected === 'rejected', $result['limit_exceeded'] !== null);
    assertSameValue(true, (bool) array_filter($db->queries, fn($sql) => str_contains($sql, 'loan_period_limits') && str_contains($sql, 'FOR UPDATE')));
    $db->pdo->commit();
    assertSameValue($expected, $db->pdo->query('SELECT status FROM loan_requests WHERE id = 1')->fetchColumn());
    $passed += 5;
}
$db = new GuarantorStatusDatabase();
$db->pdo->beginTransaction();
$result = updateLoanRequestStatus($db, 1);
assertSameValue('submitted', $result['status']);
assertSameValue(null, $result['limit_exceeded']);
$db->pdo->commit();
$passed += 2;
// Exercise the endpoint's actual pre-mutation slot check against existing rows.
$slotStart = strpos($source, '    // The locked loan serializes invitations');
$slotEnd = strpos($source, '    if ($existingRequest) {', $slotStart);
$slotCode = substr($source, $slotStart, $slotEnd - $slotStart);
$checkSlot = function ($db, $existingRequest) use ($slotCode) {
    $loan_request_id = 1;
    eval($slotCode);
};
$db = new GuarantorStatusDatabase();
$db->pdo->exec("INSERT INTO guarantor_requests VALUES (3, 1, 'old', '0.20', '0.02', 'rejected')");
foreach ([null, ['id' => 3]] as $existing) {
    try {
        $checkSlot($db, $existing);
        throw new RuntimeException('A third active invitation must be rejected');
    } catch (Exception $error) {
        assertSameValue(409, $error->getCode());
    }
    $passed++;
}
$checkSlot($db, ['id' => 1]); // Renewing an existing active row occupies its same slot.
$passed++;
$db->pdo->exec("UPDATE guarantor_requests SET status = 'rejected' WHERE id = 2");
$checkSlot($db, ['id' => 3]); // A rejected invitation may reuse a genuinely available slot.
$passed++;
$db = new GuarantorStatusDatabase();
$db->pdo->exec('DELETE FROM loan_guarantor_evidence');
$db->pdo->beginTransaction();
assertSameValue('pending_guarantors', updateLoanRequestStatus($db, 1)['status']);
$db->pdo->commit(); $passed++;
$db = new GuarantorStatusDatabase();
$db->pdo->beginTransaction(); assertSameValue('submitted', updateLoanRequestStatus($db, 1)['status']); $db->pdo->commit();
$hash = loanConsentTermsHash(['id'=>1,'requester_coop_id'=>'borrower','requested_amount'=>'0.20','monthly_repayment'=>'0.02']);
seedTestLoanEvidence($db->pdo, 1, 1, 'g1', $hash, 2, 'pending');
$db->pdo->beginTransaction(); assertSameValue('partially_guaranteed', updateLoanRequestStatus($db, 1)['status']); $db->pdo->commit(); $passed += 2;
echo "$passed guarantor status checks passed\n";
