<?php
/** Synthetic MariaDB only: php tests/member_account_database_test.php (127.0.0.1:13307). */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../libs/services/MemberAccountManager.php';
function memberAccountTestConnection(): PDO {
    $db = new PDO('mysql:host=127.0.0.1;port=13307;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec('CREATE DATABASE IF NOT EXISTS coop_test_memberaccounts');
    $db->exec('USE coop_test_memberaccounts');
    return $db;
}
$db = memberAccountTestConnection();
if (($argv[1] ?? '') === '--worker') {
    $service = new MemberAccountManager($db);
    for ($i = 0; $i < 25; $i++) {
        $result = $service->recordMemberTransaction(42, 'savings', '0.01', 10);
        if (!$result['success']) { fwrite(STDERR, json_encode($result)); exit(1); }
    }
    exit(0);
}
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['coop_member_accounts', 'coop_period_balances', 'coop_accounts', 'tbpayrollperiods', 'tblemployees'] as $table) $db->exec('DROP TABLE IF EXISTS ' . $table);
$db->exec('SET FOREIGN_KEY_CHECKS = 1');
$schema = file_get_contents(__DIR__ . '/../SETUP_FULL_ACCOUNTING_SYSTEM.sql');
foreach (['coop_accounts', 'coop_period_balances', 'coop_member_accounts'] as $table) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . '\s*\(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $match)) throw new RuntimeException('Missing fixture schema');
    $db->exec($match[0]);
}
$db->exec('CREATE TABLE tbpayrollperiods (id INT PRIMARY KEY, PayrollPeriod VARCHAR(100)) ENGINE=InnoDB');
$db->exec("CREATE TABLE tblemployees (CoopID VARCHAR(255) PRIMARY KEY, StaffID INT, Status VARCHAR(20), INDEX(StaffID)) ENGINE=InnoDB");
$db->exec("INSERT INTO tbpayrollperiods VALUES (10,'January 2026'),(11,'February 2026'),(12,'March 2026')");
$db->exec("INSERT INTO tblemployees VALUES ('M42',42,'Active'),('M43',43,'Active'),('M44',44,'Active')");
$service = new MemberAccountManager($db);
function accountRow(PDO $db, int $member, int $period): array {
    $stmt=$db->prepare("SELECT opening_balance,debit_amount,credit_amount,closing_balance FROM coop_member_accounts WHERE memberid=? AND account_type='savings' AND periodid=?");
    $stmt->execute([$member,$period]); return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
test('decimal additions and debits preserve exact kobo', function () use ($db,$service) {
    assertSameValue(true,$service->recordMemberTransaction(43,'savings','0.10',10)['success']);
    assertSameValue(true,$service->recordMemberTransaction(43,'savings','0.20',10)['success']);
    assertSameValue(true,$service->recordMemberTransaction(43,'savings','-0.01',10)['success']);
    assertSameValue(['opening_balance'=>'0.00','debit_amount'=>'0.01','credit_amount'=>'0.30','closing_balance'=>'0.29'],accountRow($db,43,10));
});
test('reject malformed precision and unknown member/account/period without writes', function () use ($db,$service) {
    foreach ([[43,'savings','0.001',10],[999,'savings','1.00',10],[43,'bogus','1.00',10],[43,'savings','1.00',999]] as $args) assertSameValue(false,$service->recordMemberTransaction(...$args)['success']);
    assertSameValue('0.29',accountRow($db,43,10)['closing_balance']);
});
test('savepoint success preserves caller transaction and caller rollback', function () use ($db,$service) {
    $db->beginTransaction();
    assertSameValue(true,$service->recordMemberTransaction(43,'savings','1.00',10)['success']);
    assertSameValue(true,$db->inTransaction());
    $db->rollBack();
    assertSameValue('0.29',accountRow($db,43,10)['closing_balance']);
});
test('savepoint failure preserves earlier caller work', function () use ($db,$service) {
    $db->beginTransaction();
    $db->exec("INSERT INTO tbpayrollperiods VALUES (99,'caller marker')");
    assertSameValue(false,$service->recordMemberTransaction(43,'savings','0.001',10)['success']);
    assertSameValue(true,$db->inTransaction());
    assertSameValue(1,(int)$db->query('SELECT COUNT(*) FROM tbpayrollperiods WHERE id=99')->fetchColumn());
    $db->rollBack();
});
test('initialization is exact, nested, and idempotent', function () use ($db,$service) {
    $db->beginTransaction();
    assertSameValue(true,$service->initializeNewPeriodBalances(11,10)['success']);
    assertSameValue(true,$db->inTransaction());
    assertSameValue('0.29',accountRow($db,43,11)['opening_balance']);
    $db->rollBack();
    assertSameValue([],accountRow($db,43,11));
    assertSameValue(true,$service->initializeNewPeriodBalances(11,10)['success']);
    assertSameValue(true,$service->recordMemberTransaction(43,'savings','0.01',11)['success']);
    assertSameValue(true,$service->initializeNewPeriodBalances(11,10)['success']);
    assertSameValue('0.30',accountRow($db,43,11)['closing_balance']);
    assertSameValue(false,$service->recordMemberTransaction(43,'savings','0.01',10)['success']);
});
test('concurrent first-row contributions do not lose updates', function () use ($db) {
    $workers=[];
    for($i=0;$i<3;$i++) {
        $pipes=[];
        $process=proc_open([PHP_BINARY,__FILE__,'--worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)) throw new RuntimeException('Cannot start worker');
        fclose($pipes[0]); $workers[]=[$process,$pipes];
    }
    foreach($workers as [$process,$pipes]) {
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit=proc_close($process);
        if($exit!==0) throw new RuntimeException('Concurrent writer failed: '.$out.$err);
    }
    assertSameValue('0.75',accountRow($db,42,10)['closing_balance']);
    assertSameValue('0.75',accountRow($db,42,10)['credit_amount']);
});
test('overflow fails without partial write', function () use ($db,$service) {
    assertSameValue(true,$service->recordMemberTransaction(44,'savings','9999999999999.99',10)['success']);
    assertSameValue(false,$service->recordMemberTransaction(44,'savings','0.01',10)['success']);
    assertSameValue('9999999999999.99',accountRow($db,44,10)['closing_balance']);
});
$db->exec('DELETE FROM coop_member_accounts WHERE memberid = 44');
foreach ([101=>['3101','equity','credit'],102=>['3201','liability','credit'],103=>['3202','liability','credit'],104=>['1110','asset','debit']] as $id=>$account) {
    $db->prepare('INSERT INTO coop_accounts (id,account_code,account_name,account_type,normal_balance,is_control_account) VALUES (?,?,?,?,?,1)')->execute([$id,$account[0],'Synthetic control',$account[1],$account[2]]);
    $db->prepare('INSERT INTO coop_period_balances (periodid,account_id,period_credit) VALUES (10,?,?)')->execute([$id,$id===102?'1.04':'0.00']);
}
test('reconciliation resolves codes to IDs and compares exact kobo', function () use ($db,$service) {
    $result=$service->reconcileMemberAccounts(10);
    assertSameValue(true,$result['all_match']);
    assertSameValue(true,$result['reconciliation']['savings']['matches']);
    $db->exec('UPDATE coop_period_balances SET period_credit=1.03 WHERE account_id=102 AND periodid=10');
    $result=$service->reconcileMemberAccounts(10);
    assertSameValue(false,$result['all_match']);
    assertSameValue(0.01,$result['reconciliation']['savings']['difference']);
});
test('closed periods reject member writes and carryforward without committing caller', function () use ($db,$service) {
    $db->exec('UPDATE coop_period_balances SET is_closed=1 WHERE periodid=10');
    assertSameValue(false,$service->recordMemberTransaction(42,'savings','1.00',10)['success']);
    $db->exec('INSERT INTO coop_period_balances (periodid,account_id,is_closed) VALUES (12,101,1)');
    $db->beginTransaction();
    assertSameValue(false,$service->initializeNewPeriodBalances(12,11)['success']);
    assertSameValue(true,$db->inTransaction());
    $db->rollBack();
    assertSameValue([],accountRow($db,43,12));
});
test('missing control configuration never reports successful reconciliation', function () use ($db,$service) {
    $db->exec("UPDATE coop_accounts SET is_active=0 WHERE account_code='3101'");
    $result=$service->reconcileMemberAccounts(10);
    assertSameValue(false,$result['all_match']);
    assertContains('Missing active control account',$result['error']);
});
finishTests();
