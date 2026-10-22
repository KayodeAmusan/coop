<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../libs/services/AccountBalanceCalculator.php';
if(getenv('COOP_BALANCE_MYSQL_TEST')!=='1'){echo "SKIP isolated balance database tests\n";exit(0);}
$server=new PDO('mysql:host=127.0.0.1;port=13307;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS coop_test_balances CHARACTER SET utf8mb4');
$db=new PDO('mysql:host=127.0.0.1;port=13307;dbname=coop_test_balances;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['coop_period_balances','coop_accounts','tbpayrollperiods'] as $table)$db->exec("DROP TABLE IF EXISTS $table");
$db->exec('SET FOREIGN_KEY_CHECKS=1');
$db->exec('CREATE TABLE tbpayrollperiods(id INT PRIMARY KEY) ENGINE=InnoDB');
$db->exec('INSERT INTO tbpayrollperiods VALUES(1)');
$schema=file_get_contents(__DIR__.'/../SETUP_FULL_ACCOUNTING_SYSTEM.sql');
foreach(['coop_accounts','coop_period_balances'] as $table){
    if(!preg_match('/CREATE TABLE IF NOT EXISTS '.$table.'\\s*\\(.*?ENGINE=InnoDB[^;]*;/s',$schema,$sql))throw new RuntimeException('Missing actual schema');
    $db->exec($sql[0]);
}
$db->exec("INSERT INTO coop_accounts(id,account_code,account_name,account_type,normal_balance) VALUES
    (1,'1102','Synthetic bank','asset','debit'),(2,'3101','Synthetic capital','equity','credit'),
    (3,'4102','Synthetic income','revenue','credit'),(4,'6001','Synthetic expense','expense','debit'),
    (5,'1502','Synthetic accumulated depreciation','asset','credit')");
$calc=new AccountBalanceCalculator($db);
function balanceFixture($rows){
    global $db;
    $db->exec('DELETE FROM coop_period_balances');
    $stmt=$db->prepare('INSERT INTO coop_period_balances(periodid,account_id,opening_debit,opening_credit,period_debit,period_credit) VALUES(1,?,?,?,?,?)');
    foreach($rows as $row)$stmt->execute($row);
}
test('balance calculation adds decimal tenths exactly',function()use($calc){
    balanceFixture([[1,'0.10','0.00','0.20','0.00']]);
    assertSameValue('0.30',$calc->getAccountBalance(1,1)['balance_decimal']);
});
test('trial balance refuses a one-kobo mismatch',function()use($calc){
    balanceFixture([[1,'0.00','0.00','0.30','0.00'],[2,'0.00','0.00','0.00','0.29']]);
    $trial=$calc->getTrialBalance(1);
    assertSameValue(false,$trial['is_balanced']);
    assertSameValue('0.01',$trial['totals']['difference_decimal']);
});
test('inactive historical accounts remain in the trial balance',function()use($db,$calc){
    $db->exec('UPDATE coop_accounts SET is_active=0 WHERE id=2');
    balanceFixture([[1,'0.00','0.00','0.30','0.00'],[2,'0.00','0.00','0.00','0.30']]);
    assertSameValue(true,$calc->getTrialBalance(1)['is_balanced']);
    assertSameValue(2,count($calc->getTrialBalance(1)['accounts']));
    $db->exec('UPDATE coop_accounts SET is_active=1 WHERE id=2');
});
test('accounting equation includes unclosed current earnings',function()use($calc){
    balanceFixture([[1,'0.00','0.00','10.00','0.00'],[2,'0.00','0.00','0.00','6.00'],[3,'0.00','0.00','0.00','4.00']]);
    $equation=$calc->verifyAccountingEquation(1);
    assertSameValue(true,$equation['valid']);
    assertSameValue('0.00',$equation['difference_decimal']);
    assertSameValue(4.0,$equation['current_earnings']);
});
test('contra assets reduce assets rather than increasing them',function()use($calc){
    balanceFixture([[1,'0.00','0.00','10.00','0.00'],[2,'0.00','0.00','0.00','7.00'],[5,'0.00','0.00','0.00','3.00']]);
    assertSameValue('7.00',$calc->getTotalByTypeDecimal(1,'asset'));
    assertSameValue(true,$calc->verifyAccountingEquation(1)['valid']);
});
test('signed balances and missing-account errors are explicit',function()use($calc){
    balanceFixture([[1,'0.00','0.00','0.00','0.01']]);
    assertSameValue('-0.01',$calc->getAccountBalance(1,1)['balance_decimal']);
    assertContains('Account not found',$calc->getAccountBalance(999,1)['error']);
});
finishTests();
