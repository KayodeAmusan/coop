<?php
// Explicit opt-in and a fixed disposable database: never load production configuration.
if (PHP_SAPI !== 'cli' || getenv('COOP_APPLICATION_MYSQL_TEST') !== '1') {
    fwrite(STDERR, "Run only with COOP_APPLICATION_MYSQL_TEST=1 against isolated local MariaDB.\n");
    exit(2);
}
require_once __DIR__ . '/../includes/loan_application_helper.php';
function applicationTestDb() {
    return new PDO('mysql:host=127.0.0.1;port=13307;dbname=coop_test_applications;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
if (($argv[1] ?? '') === 'worker') {
    $db = applicationTestDb();
    $db->beginTransaction();
    try {
        lockLoanApplicationBorrower($db, 'borrower', 1);
        usleep(300000);
        $db->exec("INSERT INTO loan_requests (requester_coop_id, period_id, status) VALUES ('borrower', 1, 'draft')");
        $db->commit();
        echo 'created';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e->getCode() !== 409) throw $e;
        echo 'duplicate';
    }
    exit;
}
$db = new PDO('mysql:host=127.0.0.1;port=13307;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE DATABASE IF NOT EXISTS coop_test_applications');
$db = applicationTestDb();
$db->exec('DROP TABLE IF EXISTS loan_requests');
$db->exec('DROP TABLE IF EXISTS tblemployees');
$db->exec('CREATE TABLE tblemployees (CoopID VARCHAR(30) PRIMARY KEY, StaffID INTEGER, Status VARCHAR(20)) ENGINE=InnoDB');
$db->exec('CREATE TABLE loan_requests (id INTEGER AUTO_INCREMENT PRIMARY KEY, requester_coop_id VARCHAR(30), period_id INTEGER, status VARCHAR(30)) ENGINE=InnoDB');
$db->exec("INSERT INTO tblemployees VALUES ('borrower', 12, 'Active')");
function checkApplication($ok, $label) {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
foreach (['draft', 'pending_guarantors', 'partially_guaranteed', 'fully_guaranteed', 'submitted', 'approved'] as $status) {
    $db->exec('DELETE FROM loan_requests');
    $db->exec("INSERT INTO loan_requests (requester_coop_id, period_id, status) VALUES ('borrower', 1, '$status')");
    $db->beginTransaction();
    try {
        lockLoanApplicationBorrower($db, 'borrower', 1);
        throw new RuntimeException('Duplicate accepted');
    } catch (Exception $e) {
        checkApplication($e->getCode() === 409, "$status blocks a second application");
    } finally { $db->rollBack(); }
}
$db->beginTransaction();
$member = lockLoanApplicationBorrower($db, 'borrower', 2);
checkApplication((int)$member['StaffID'] === 12, 'another payroll period remains available');
$db->rollBack();
$db->exec("UPDATE tblemployees SET Status = 'Inactive'");
$db->beginTransaction();
try {
    lockLoanApplicationBorrower($db, 'borrower', 2);
    throw new RuntimeException('Inactive borrower accepted');
} catch (Exception $e) {
    checkApplication($e->getCode() === 404, 'inactive borrower rejected');
} finally { $db->rollBack(); }
$db->exec("UPDATE tblemployees SET Status = 'Active'");
$db->exec('DELETE FROM loan_requests');
$processes = [];
for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start concurrent worker');
    fclose($pipes[0]);
    $processes[] = [$process, $pipes];
}
$results = [];
foreach ($processes as [$process, $pipes]) {
    $results[] = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    checkApplication(proc_close($process) === 0, 'concurrent worker succeeds: ' . $error);
}
sort($results);
checkApplication($results === ['created', 'duplicate'], 'concurrent applications serialize with one conflict');
checkApplication((int)$db->query('SELECT COUNT(*) FROM loan_requests')->fetchColumn() === 1, 'exactly one application persisted');
foreach (['loans/request.php', 'admin/manual-loan.php'] as $endpoint) {
    $source = file_get_contents(__DIR__ . '/../auth_api/api/' . $endpoint);
    checkApplication(strpos($source, 'requireApplicationTerms(') !== false, "$endpoint enforces configured product gate");
    checkApplication(strpos($source, 'INSERT INTO loan_requests') === false, "$endpoint cannot insert an unconfigured application");
}
