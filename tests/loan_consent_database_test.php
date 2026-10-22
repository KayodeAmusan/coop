<?php
/**
 * Execute the consent join against real SQLite data, without production access.
 * SQLite does not implement MySQL FOR UPDATE: the adapter removes that suffix.
 * These tests verify query/data integration and rollback, not locking or concurrency.
 * Run: php tests/loan_consent_database_test.php
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/loan_consent_helper.php';
require_once __DIR__ . '/loan_evidence_fixture.php';

class ConsentSqliteAdapter
{
    public $pdo;
    public $queries = [];

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE tblemployees (CoopID TEXT PRIMARY KEY, Status TEXT)');
        $this->pdo->exec('CREATE TABLE guarantor_requests (
            id INTEGER PRIMARY KEY, loan_request_id INTEGER, guarantor_coop_id TEXT,
            status TEXT, response_date TEXT, requested_amount TEXT,
            monthly_repayment TEXT, responded_by_coop_id TEXT, consent_terms_hash TEXT)');
        $this->pdo->exec('CREATE TABLE loan_requests (id INTEGER PRIMARY KEY, status TEXT)');
        createTestLoanEvidenceTable($this->pdo);
        $this->pdo->exec('CREATE TABLE notifications (loan_id INTEGER, message TEXT)');
        $this->pdo->exec("INSERT INTO loan_requests VALUES (10, 'submitted')");
    }

    public function inTransaction() { return $this->pdo->inTransaction(); }
    public function beginTransaction() { return $this->pdo->beginTransaction(); }
    public function rollBack() { return $this->pdo->rollBack(); }
    public function prepare($sql)
    {
        $this->queries[] = $sql;
        return $this->pdo->prepare(preg_replace('/\s+FOR UPDATE\s*$/i', '', $sql));
    }
}

function databaseConsentLoan()
{
    return ['id' => 10, 'requester_coop_id' => '00123', 'requested_amount' => '5000.00',
        'monthly_repayment' => '500.00', 'status' => 'submitted'];
}

function seedDatabaseConsent($db, $loan)
{
    $member = $db->pdo->prepare('INSERT INTO tblemployees VALUES (?, ?)');
    $consent = $db->pdo->prepare('INSERT INTO guarantor_requests VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ([1 => '00456', 2 => '00789'] as $id => $memberId) {
        $member->execute([$memberId, 'Active']);
        $consent->execute([$id, $loan['id'], $memberId, 'approved', '2026-10-21 12:00:00',
            $loan['requested_amount'], $loan['monthly_repayment'], $memberId, loanConsentTermsHash($loan)]);
        seedTestLoanEvidence($db->pdo, $loan['id'], $id, $memberId, loanConsentTermsHash($loan));
    }
}

function expectDatabaseConsentFailure(callable $body, $class, $code = null)
{
    try {
        $body();
    } catch (Throwable $error) {
        if (!($error instanceof $class)) {
            throw new RuntimeException('Expected ' . $class . ', got ' . get_class($error));
        }
        if ($code !== null) assertSameValue($code, $error->getCode());
        return;
    }
    throw new RuntimeException('Expected consent verification to reject the operation');
}

test('requires a caller transaction before executing the consent query', function () {
    $db = new ConsentSqliteAdapter();
    expectDatabaseConsentFailure(function () use ($db) {
        requireLoanConsent($db, databaseConsentLoan(), '5000.00');
    }, LogicException::class);
    assertSameValue([], $db->queries);
});

test('executes the joined consent query with matching members and current evidence', function () {
    $db = new ConsentSqliteAdapter();
    $loan = databaseConsentLoan();
    seedDatabaseConsent($db, $loan);
    $db->beginTransaction();
    requireLoanConsent($db, $loan, '5000.00');
    assertSameValue(true, $db->inTransaction());
    assertContains('LEFT JOIN tblemployees', $db->queries[0]);
    assertContains('FOR UPDATE', $db->queries[0]);
    $db->rollBack();
});

foreach ([
    'missing consent' => 'DELETE FROM guarantor_requests WHERE id = 1',
    'missing member' => "DELETE FROM tblemployees WHERE CoopID = '00456'",
    'inactive joined member' => "UPDATE tblemployees SET Status = 'Inactive' WHERE CoopID = '00456'",
    'missing reviewer' => 'UPDATE loan_guarantor_evidence SET reviewed_by_id = NULL WHERE guarantor_request_id = 1',
    'member reviewer' => "UPDATE loan_guarantor_evidence SET reviewed_by_type = 'member' WHERE guarantor_request_id = 1",
    'missing proof' => 'DELETE FROM loan_guarantor_evidence WHERE guarantor_request_id = 1',
    'stale signed hash' => "UPDATE loan_guarantor_evidence SET terms_hash = 'old' WHERE guarantor_request_id = 1",
    'stale stored terms' => "UPDATE guarantor_requests SET requested_amount = '4999.99' WHERE id = 1",
    'another loan consent' => 'UPDATE guarantor_requests SET loan_request_id = 11 WHERE id = 1',
] as $name => $mutation) {
    test('database query rejects ' . $name, function () use ($mutation) {
        $db = new ConsentSqliteAdapter();
        $loan = databaseConsentLoan();
        seedDatabaseConsent($db, $loan);
        $db->pdo->exec($mutation);
        $db->beginTransaction();
        expectDatabaseConsentFailure(function () use ($db, $loan) {
            requireLoanConsent($db, $loan, '5000.00');
        }, Exception::class, 409);
        assertSameValue(true, $db->inTransaction());
        $db->rollBack();
    });
}

test('caller rolls back approval changes and notifications when consent fails', function () {
    $db = new ConsentSqliteAdapter();
    $loan = databaseConsentLoan();
    seedDatabaseConsent($db, $loan);
    $db->pdo->exec('DELETE FROM guarantor_requests WHERE id = 1');
    $db->beginTransaction();
    $db->pdo->exec("UPDATE loan_requests SET status = 'approved' WHERE id = 10");
    $db->pdo->exec("INSERT INTO notifications VALUES (10, 'Loan approved')");
    expectDatabaseConsentFailure(function () use ($db, $loan) {
        requireLoanConsent($db, $loan, '5000.00');
    }, Exception::class, 409);
    $db->rollBack();
    assertSameValue('submitted', $db->pdo->query('SELECT status FROM loan_requests WHERE id = 10')->fetchColumn());
    assertSameValue(0, (int) $db->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
});

test('latest pending upload invalidates older accepted evidence without fallback', function () {
    $db = new ConsentSqliteAdapter(); $loan = databaseConsentLoan(); seedDatabaseConsent($db, $loan);
    seedTestLoanEvidence($db->pdo, 10, 1, '00456', loanConsentTermsHash($loan), 2, 'pending');
    $db->beginTransaction();
    expectDatabaseConsentFailure(function () use ($db, $loan) { requireLoanConsent($db, $loan, '5000.00'); }, Exception::class, 409);
    $db->rollBack();
});
finishTests();
