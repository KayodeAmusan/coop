<?php
/** Run only against the disposable loopback MariaDB; fixtures use no real members. */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../libs/services/PeriodClosingProcessor.php';
require_once __DIR__ . '/../libs/services/BankReconciliationService.php';
$dsn = getenv('COOP_WORKFLOWS_TEST_DSN');
if (!$dsn || !preg_match('/\Amysql:host=127\.0\.0\.1;port=13307;dbname=(coop_test_(?:closing|reconciliation));charset=utf8mb4\z/', $dsn, $match)) {
    fwrite(STDERR, "Set COOP_WORKFLOWS_TEST_DSN to isolated loopback:13307 coop_test_closing or coop_test_reconciliation.\n"); exit(2);
}
function workflowDb($dsn) { return new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]); }
function workflowMigration($db,$file) {
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,true);$stmt=$db->query(file_get_contents($file));
    do {if($stmt->columnCount())$stmt->fetchAll();}while($stmt->nextRowset());$stmt->closeCursor();$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
}
if (($argv[1] ?? '') === 'reconciliation-worker') {
    $result = (new BankReconciliationService(workflowDb($dsn)))->createReconciliation(workflowReconciliation());
    echo json_encode($result); exit($result['success'] ? 0 : 1);
}
if (($argv[1] ?? '') === 'close-worker') {
    $result = (new PeriodClosingProcessor(workflowDb($dsn)))->closePeriod(1, 1);
    echo json_encode($result); exit($result['success'] ? 0 : 1);
}
$database = $match[1];
workflowDb('mysql:host=127.0.0.1;port=13307;charset=utf8mb4')->exec("CREATE DATABASE IF NOT EXISTS `$database`");
$db = workflowDb($dsn);
$tables = ['coop_appropriation','coop_bank_reconciliation','coop_member_accounts','coop_journal_entry_lines','coop_period_balances','coop_audit_trail','coop_journal_entries','coop_accounts','coop_journal_sequences','tbpayrollperiods'];
foreach ($tables as $table) { $db->exec("DROP TABLE IF EXISTS `$table`"); }
$db->exec('CREATE TABLE tbpayrollperiods (id INT PRIMARY KEY, PayrollPeriod VARCHAR(100)) ENGINE=InnoDB');
$schema = file_get_contents(__DIR__ . '/../SETUP_FULL_ACCOUNTING_SYSTEM.sql');
foreach (['coop_accounts','coop_journal_entries','coop_journal_entry_lines','coop_period_balances','coop_member_accounts','coop_appropriation','coop_bank_reconciliation','coop_audit_trail'] as $table) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . '\s*\(.*?ENGINE=InnoDB[^;]*;/s', $schema, $sql)) { throw new RuntimeException('Missing fixture ' . $table); }
    $db->exec($sql[0]);
}
workflowMigration($db,__DIR__ . '/../database/migrations/20261021_ledger_sequences.sql');
workflowMigration($db,__DIR__ . '/../database/migrations/20261021_journal_idempotency.sql');
workflowMigration($db,__DIR__ . '/../database/migrations/20261021_bank_reconciliation_idempotency.sql');
function workflowSuccess($result) { if (!$result['success']) { throw new RuntimeException($result['error']); } return $result; }
function workflowPost($db, $debitId, $creditId, $amount, $period = 1) {
    $engine = new AccountingEngine($db);
    $entry = workflowSuccess($engine->createJournalEntry($period, '2026-10-21', 'manual', 'Synthetic workflow', [
        ['account_id'=>$debitId,'debit_amount'=>$amount],['account_id'=>$creditId,'credit_amount'=>$amount]
    ],1));
    workflowSuccess($engine->postEntry($entry['entry_id'])); return $entry;
}
function workflowReset($db, $tables) {
    foreach ($tables as $table) { $db->exec("DELETE FROM `$table`"); }
    $db->exec("INSERT INTO tbpayrollperiods VALUES (1,'October 2026'),(2,'November 2026')");
    $db->exec("INSERT INTO coop_accounts (id,account_code,account_name,account_type,normal_balance) VALUES
        (101,'1102','Bank','asset','debit'),(102,'3201','Savings','equity','credit'),
        (103,'4299','Interest income','revenue','credit'),(104,'6009','Bank charges','expense','debit'),
        (105,'3401','Retained earnings','equity','credit'),(106,'3301','Reserve','equity','credit')");
    workflowPost($db,101,102,'100.00');
}
function workflowCount($db, $table) { return (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
function workflowNet($db, $account, $period = 1) {
    return $db->query('SELECT CAST(opening_debit+period_debit-opening_credit-period_credit AS DECIMAL(15,2)) FROM coop_period_balances WHERE account_id=' . (int) $account . ' AND periodid=' . (int) $period)->fetchColumn();
}
function workflowReconciliation($changes = []) {
    return array_merge(['periodid'=>1,'bank_account_id'=>101,'reconciliation_date'=>'2026-10-21',
        'bank_statement_balance'=>'99.90','book_balance'=>'100.00','outstanding_deposits'=>'0.00','outstanding_withdrawals'=>'0.00',
        'bank_charges'=>'0.30','bank_interest'=>'0.20','reconciled_by'=>1,'notes'=>'','idempotency_key'=>'workflow-reconciliation'], $changes);
}

test('closing individual accounts and appropriation preserve exact retained result', function () use ($db,$tables) {
    workflowReset($db,$tables);
    workflowPost($db,101,103,'0.30'); workflowPost($db,104,101,'0.10');
    $db->exec("INSERT INTO coop_member_accounts (memberid,account_type,periodid,closing_balance) VALUES (1,'savings',1,10.05)");
    $result = workflowSuccess((new PeriodClosingProcessor($db))->closePeriod(1,1,['surplus_amount'=>'0.20','reserve_fund'=>'0.05','retained_earnings'=>'0.15']));
    assertSameValue(2,count($result['entries_created']));
    assertSameValue('0.00',workflowNet($db,103)); assertSameValue('0.00',workflowNet($db,104));
    assertSameValue('-0.15',workflowNet($db,105)); assertSameValue('-0.05',workflowNet($db,106));
    assertSameValue('100.20',workflowNet($db,101,2));
    assertSameValue('10.05',$db->query('SELECT closing_balance FROM coop_member_accounts WHERE periodid=2')->fetchColumn());
    assertSameValue('1',(string)$db->query('SELECT COUNT(*) FROM coop_appropriation a JOIN coop_journal_entries j ON j.id=a.journal_entry_id WHERE j.status="posted"')->fetchColumn());
    assertSameValue('0.00',$db->query('SELECT CAST(SUM(opening_debit+period_debit-opening_credit-period_credit) AS DECIMAL(15,2)) FROM coop_period_balances WHERE periodid=1')->fetchColumn());
    assertSameValue(false,(new PeriodClosingProcessor($db))->closePeriod(1,1)['success']);
});
test('breakeven revenue and expenses still close their individual balances',function () use ($db,$tables) {
    workflowReset($db,$tables); workflowPost($db,101,103,'0.30');workflowPost($db,104,101,'0.30');
    workflowSuccess((new PeriodClosingProcessor($db))->closePeriod(1,1));
    assertSameValue('0.00',workflowNet($db,103));assertSameValue('0.00',workflowNet($db,104));
});
test('closing a loss posts retained earnings debit without zero journal lines',function () use ($db,$tables) {
    workflowReset($db,$tables);workflowPost($db,104,101,'0.30');
    workflowSuccess((new PeriodClosingProcessor($db))->closePeriod(1,1,['surplus_amount'=>'-0.30','retained_earnings'=>'-0.30']));
    assertSameValue('0.00',workflowNet($db,104)); assertSameValue('0.30',workflowNet($db,105));
});
test('closing rolls back all journals if appropriation exceeds surplus',function () use ($db,$tables) {
    workflowReset($db,$tables);workflowPost($db,101,103,'0.30');$before=workflowCount($db,'coop_journal_entries');
    assertSameValue(false,(new PeriodClosingProcessor($db))->closePeriod(1,1,['surplus_amount'=>'0.30','reserve_fund'=>'0.31'])['success']);
    assertSameValue($before,workflowCount($db,'coop_journal_entries'));assertSameValue('-0.30',workflowNet($db,103));
    assertSameValue('0',(string)$db->query('SELECT COUNT(*) FROM coop_period_balances WHERE is_closed=1')->fetchColumn());
});
test('closing journal audit failure cannot mark closed or copy openings',function () use ($db,$tables) {
    workflowReset($db,$tables);workflowPost($db,101,103,'0.30');$before=workflowCount($db,'coop_journal_entries');
    $db->exec("CREATE TRIGGER workflow_fail_post BEFORE INSERT ON coop_audit_trail FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic journal audit failure'");
    try {assertSameValue(false,(new PeriodClosingProcessor($db))->closePeriod(1,1)['success']);}
    finally {$db->exec('DROP TRIGGER workflow_fail_post');}
    assertSameValue($before,workflowCount($db,'coop_journal_entries'));assertSameValue('0',(string)$db->query('SELECT COUNT(*) FROM coop_period_balances WHERE is_closed=1 OR periodid=2')->fetchColumn());
});
test('member initialization failure rolls back closure and journals',function () use ($db,$tables) {
    workflowReset($db,$tables);workflowPost($db,101,103,'0.30');$before=workflowCount($db,'coop_journal_entries');
    $db->exec("INSERT INTO coop_member_accounts (memberid,account_type,periodid,closing_balance) VALUES (1,'savings',1,10.05)");
    $db->exec("CREATE TRIGGER workflow_fail_member BEFORE INSERT ON coop_member_accounts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic member initialization failure'");
    try {$result=(new PeriodClosingProcessor($db))->closePeriod(1,1);assertSameValue(false,$result['success']);assertContains('Synthetic member initialization failure',$result['error']);}
    finally {$db->exec('DROP TRIGGER workflow_fail_member');}
    assertSameValue($before,workflowCount($db,'coop_journal_entries'));assertSameValue('0',(string)$db->query('SELECT COUNT(*) FROM coop_period_balances WHERE is_closed=1 OR periodid=2')->fetchColumn());
});
test('closing preserves outer rollback and next-period movements',function () use ($db,$tables) {
    workflowReset($db,$tables);workflowPost($db,101,102,'5.00',2);
    $before=workflowCount($db,'coop_journal_entries');$db->beginTransaction();
    workflowSuccess((new PeriodClosingProcessor($db))->closePeriod(1,1));
    assertSameValue(true,$db->inTransaction());assertSameValue('105.00',workflowNet($db,101,2));
    $db->rollBack();assertSameValue($before,workflowCount($db,'coop_journal_entries'));assertSameValue('5.00',workflowNet($db,101,2));
    assertSameValue('0',(string)$db->query('SELECT COUNT(*) FROM coop_period_balances WHERE is_closed=1')->fetchColumn());
});
test('empty periods are rejected because schema has no closed-period marker',function () use ($db,$tables) {
    workflowReset($db,$tables);assertSameValue(false,(new PeriodClosingProcessor($db))->closePeriod(2,1)['success']);
});
test('post racing closure waits then rejects closed period without balance changes',function () use ($db,$tables,$dsn,$database) {
    workflowReset($db,$tables);workflowPost($db,101,103,'0.30');
    $engine=new AccountingEngine($db);$entry=workflowSuccess($engine->createJournalEntry(1,'2026-10-21','manual','racing post',[
        ['account_id'=>101,'debit_amount'=>'1.00'],['account_id'=>103,'credit_amount'=>'1.00']],1));
    $db->exec("CREATE TRIGGER workflow_slow_close BEFORE UPDATE ON coop_period_balances FOR EACH ROW BEGIN IF NEW.is_closed=1 AND OLD.is_closed=0 AND NEW.account_id=101 THEN DO SLEEP(1); END IF; END");
    $worker=proc_open([PHP_BINARY,__FILE__,'close-worker'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    try {
        $sleeping=false;
        for($i=0;$i<100;$i++) {
            $stmt=$db->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=? AND STATE='User sleep'");$stmt->execute([$database]);
            if($stmt->fetchColumn()>0){$sleeping=true;break;}usleep(10000);
        }
        assertSameValue(true,$sleeping);assertSameValue(false,$engine->postEntry($entry['entry_id'])['success']);
        $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        assertSameValue(0,proc_close($worker));workflowSuccess(json_decode($output,true,512,JSON_THROW_ON_ERROR));
        assertSameValue('draft',$engine->getJournalEntry($entry['entry_id'])['status']);assertSameValue('100.30',workflowNet($db,101));
    } finally {$db->exec('DROP TRIGGER workflow_slow_close');}
});
test('bank reconciliation posts exact charges and interest atomically',function () use ($db,$tables) {
    workflowReset($db,$tables);
    $result=workflowSuccess((new BankReconciliationService($db))->createReconciliation(workflowReconciliation()));
    assertSameValue(true,$result['is_balanced']);assertSameValue('0.00',$result['variance']);assertSameValue('99.90',workflowNet($db,101));
    assertSameValue(1,workflowCount($db,'coop_bank_reconciliation'));assertSameValue(3,workflowCount($db,'coop_journal_entries'));
    assertSameValue('2',(string)$db->query("SELECT COUNT(*) FROM coop_journal_entries WHERE source_document LIKE 'BANK-RECON-%' AND status='posted'")->fetchColumn());
});
test('repeat-safe bank migration preserves unkeyed historical records without posting',function()use($db,$tables){
    workflowReset($db,$tables);$journals=workflowCount($db,'coop_journal_entries');
    $db->exec("INSERT INTO coop_bank_reconciliation(periodid,bank_account_id,reconciliation_date,bank_statement_balance,book_balance,reconciled_balance,is_balanced,variance,reconciled_by,notes) VALUES(1,101,'2026-10-20',100.00,100.00,100.00,1,0.00,1,'Legacy synthetic')");$id=$db->lastInsertId();
    workflowMigration($db,__DIR__.'/../database/migrations/20261021_bank_reconciliation_idempotency.sql');workflowMigration($db,__DIR__.'/../database/migrations/20261021_bank_reconciliation_idempotency.sql');
    $row=$db->query('SELECT bank_statement_balance,idempotency_key,request_hash,result_json FROM coop_bank_reconciliation WHERE id='.(int)$id)->fetch(PDO::FETCH_ASSOC);
    assertSameValue(['bank_statement_balance'=>'100.00','idempotency_key'=>null,'request_hash'=>null,'result_json'=>null],$row);assertSameValue($journals,workflowCount($db,'coop_journal_entries'));
    workflowMigration($db,__DIR__.'/../database/migrations/20261021_bank_reconciliation_idempotency_down.sql');
    assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_bank_reconciliation' AND COLUMN_NAME='idempotency_key'")->fetchColumn());
    assertSameValue('100.00',$db->query('SELECT bank_statement_balance FROM coop_bank_reconciliation WHERE id='.(int)$id)->fetchColumn());
    workflowMigration($db,__DIR__.'/../database/migrations/20261021_bank_reconciliation_idempotency.sql');
});
test('reconciliation retry returns original result and journal links despite changed book and closed period',function()use($db,$tables){
    workflowReset($db,$tables);$service=new BankReconciliationService($db);$input=workflowReconciliation();
    $first=workflowSuccess($service->createReconciliation($input));$journals=workflowCount($db,'coop_journal_entries');$audits=workflowCount($db,'coop_audit_trail');
    assertSameValue(false,$first['replayed']);assertSameValue(2,count($first['adjusting_entry_ids']));
    workflowMigration($db,__DIR__.'/../database/migrations/20261021_bank_reconciliation_idempotency_down.sql');
    assertSameValue(1,(int)$db->query("SELECT COUNT(*) FROM coop_bank_reconciliation WHERE idempotency_key='workflow-reconciliation' AND result_json IS NOT NULL")->fetchColumn());
    $db->exec('UPDATE coop_period_balances SET is_closed=1 WHERE periodid=1');
    $retry=workflowSuccess($service->createReconciliation($input));assertSameValue(true,$retry['replayed']);
    $retry['replayed']=false;assertSameValue($first,$retry);
    assertSameValue($journals,workflowCount($db,'coop_journal_entries'));assertSameValue($audits,workflowCount($db,'coop_audit_trail'));assertSameValue('99.90',workflowNet($db,101));
    foreach($first['adjusting_entry_ids']as$id){assertSameValue('2026-10-21',$db->query('SELECT entry_date FROM coop_journal_entries WHERE id='.(int)$id)->fetchColumn());}
});
test('canonical equivalent money replays while every changed financial field conflicts',function()use($db,$tables){
    workflowReset($db,$tables);$service=new BankReconciliationService($db);$input=workflowReconciliation();$first=workflowSuccess($service->createReconciliation($input));
    $same=$input;$same['book_balance']='100.0';$same['bank_charges']='0.3';$same['notes']='  ';assertSameValue(true,workflowSuccess($service->createReconciliation($same))['replayed']);
    foreach(['periodid'=>2,'bank_account_id'=>102,'reconciliation_date'=>'2026-10-22','bank_statement_balance'=>'99.91','book_balance'=>'99.90','outstanding_deposits'=>'0.01','outstanding_withdrawals'=>'0.01','bank_charges'=>'0.31','bank_interest'=>'0.21','notes'=>'changed']as$field=>$value){
        $changed=$input;$changed[$field]=$value;$result=$service->createReconciliation($changed);assertSameValue(false,$result['success']);assertSameValue(409,$result['error_code']);
    }
    assertSameValue(1,workflowCount($db,'coop_bank_reconciliation'));assertSameValue(3,workflowCount($db,'coop_journal_entries'));
});
test('missing key, unknown fields and invalid explicit date cannot write financial records',function()use($db,$tables){
    workflowReset($db,$tables);$service=new BankReconciliationService($db);$before=workflowCount($db,'coop_audit_trail');
    foreach(['idempotency_key','reconciliation_date','notes','bank_charges']as$field){$data=workflowReconciliation();unset($data[$field]);assertSameValue(400,$service->createReconciliation($data)['error_code']);}
    $data=workflowReconciliation(['reconciliation_date'=>'2026-02-30']);assertSameValue(400,$service->createReconciliation($data)['error_code']);
    $data=workflowReconciliation(['unknown'=>'unsafe']);assertSameValue(400,$service->createReconciliation($data)['error_code']);
    assertSameValue(0,workflowCount($db,'coop_bank_reconciliation'));assertSameValue($before,workflowCount($db,'coop_audit_trail'));
});
test('concurrent same-key reconciliation commands missing initial lookup commit once',function()use($db,$tables,$dsn,$database){
    workflowReset($db,$tables);$beforeAudit=workflowCount($db,'coop_audit_trail');
    $db->beginTransaction();$db->query('SELECT id FROM tbpayrollperiods WHERE id=1 FOR UPDATE')->fetchAll();
    $workers=[];$waiting=0;
    try {
        for($i=0;$i<3;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'reconciliation-worker'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot start reconciliation worker');$workers[]=[$process,$pipes];}
        for($i=0;$i<300;$i++){
            $waiting=(int)$db->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND INFO LIKE 'SELECT id FROM tbpayrollperiods WHERE id = %FOR UPDATE'")->fetchColumn();if($waiting>=3)break;usleep(10000);
        }
    } finally {if($db->inTransaction())$db->commit();}
    $ids=[];$originals=0;
    foreach($workers as[$process,$pipes]){$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);assertSameValue(0,proc_close($process));$result=workflowSuccess(json_decode($output,true,512,JSON_THROW_ON_ERROR));$ids[]=$result['reconciliation_id'];if(!$result['replayed'])$originals++;}
    assertSameValue(true,$waiting>=3);assertSameValue(1,$originals);assertSameValue(1,count(array_unique($ids)));assertSameValue(1,workflowCount($db,'coop_bank_reconciliation'));assertSameValue(3,workflowCount($db,'coop_journal_entries'));assertSameValue($beforeAudit+3,workflowCount($db,'coop_audit_trail'));assertSameValue('99.90',workflowNet($db,101));
});
test('command keys are scoped to their trusted reconciliation actor',function()use($db,$tables){
    workflowReset($db,$tables);$service=new BankReconciliationService($db);$first=workflowSuccess($service->createReconciliation(workflowReconciliation()));
    $other=workflowSuccess($service->createReconciliation(workflowReconciliation(['reconciled_by'=>2,'book_balance'=>'99.90','bank_statement_balance'=>'99.90','bank_charges'=>'0.00','bank_interest'=>'0.00'])));
    assertSameValue(false,$other['replayed']);assertSameValue(false,$first['reconciliation_id']===$other['reconciliation_id']);assertSameValue(2,workflowCount($db,'coop_bank_reconciliation'));assertSameValue(3,workflowCount($db,'coop_journal_entries'));
});
test('result snapshot persistence failure rolls journals and command back for safe retry',function()use($db,$tables){
    workflowReset($db,$tables);$before=workflowCount($db,'coop_audit_trail');$service=new BankReconciliationService($db);
    $db->exec("CREATE TRIGGER workflow_fail_recon_result BEFORE UPDATE ON coop_bank_reconciliation FOR EACH ROW BEGIN IF NEW.result_json IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic result persistence failure'; END IF; END");
    try{assertSameValue(false,$service->createReconciliation(workflowReconciliation())['success']);}finally{$db->exec('DROP TRIGGER workflow_fail_recon_result');}
    assertSameValue(0,workflowCount($db,'coop_bank_reconciliation'));assertSameValue(1,workflowCount($db,'coop_journal_entries'));assertSameValue($before,workflowCount($db,'coop_audit_trail'));assertSameValue('100.00',workflowNet($db,101));
    assertSameValue(false,workflowSuccess($service->createReconciliation(workflowReconciliation()))['replayed']);
});
test('reconciliation create audit failure also rolls back commands without adjustments',function()use($db,$tables){
    workflowReset($db,$tables);$service=new BankReconciliationService($db);$input=workflowReconciliation(['bank_statement_balance'=>'100.00','bank_charges'=>'0.00','bank_interest'=>'0.00']);
    $db->exec("CREATE TRIGGER workflow_fail_recon_audit BEFORE INSERT ON coop_audit_trail FOR EACH ROW BEGIN IF NEW.table_name='coop_bank_reconciliation' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic reconciliation create audit failure'; END IF; END");
    try {assertSameValue(false,$service->createReconciliation($input)['success']);}finally{$db->exec('DROP TRIGGER workflow_fail_recon_audit');}
    assertSameValue(0,workflowCount($db,'coop_bank_reconciliation'));assertSameValue(1,workflowCount($db,'coop_journal_entries'));
    assertSameValue(false,workflowSuccess($service->createReconciliation($input))['replayed']);assertSameValue(true,workflowSuccess($service->createReconciliation($input))['replayed']);
});
test('one kobo bank variance is not balanced and stale book input is rejected',function () use ($db,$tables) {
    workflowReset($db,$tables);
    $service=new BankReconciliationService($db);
    $result=workflowSuccess($service->createReconciliation(workflowReconciliation(['bank_statement_balance'=>'100.01','bank_charges'=>'0.00','bank_interest'=>'0.00'])));
    assertSameValue(false,$result['is_balanced']);assertSameValue('0.01',$result['variance']);
    assertSameValue(false,$service->createReconciliation(workflowReconciliation(['book_balance'=>'99.99']))['success']);
    assertSameValue(1,workflowCount($db,'coop_bank_reconciliation'));
});
test('second bank adjustment failure rolls back first journal and reconciliation',function () use ($db,$tables) {
    workflowReset($db,$tables);$before=workflowCount($db,'coop_journal_entries');
    $db->exec("CREATE TRIGGER workflow_fail_interest BEFORE INSERT ON coop_journal_entries FOR EACH ROW BEGIN IF NEW.source_document LIKE '%-INTEREST' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic interest failure';END IF;END");
    try {assertSameValue(false,(new BankReconciliationService($db))->createReconciliation(workflowReconciliation())['success']);}
    finally {$db->exec('DROP TRIGGER workflow_fail_interest');}
    assertSameValue($before,workflowCount($db,'coop_journal_entries'));assertSameValue(0,workflowCount($db,'coop_bank_reconciliation'));assertSameValue('100.00',workflowNet($db,101));
});
test('bank audit post failure rolls back reconciliation and preserves caller work',function () use ($db,$tables) {
    workflowReset($db,$tables);$before=workflowCount($db,'coop_journal_entries');
    $db->exec("CREATE TRIGGER workflow_bank_audit BEFORE INSERT ON coop_audit_trail FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic bank post failure'");
    try {
        $db->beginTransaction();$db->exec("UPDATE coop_accounts SET account_name='Caller work' WHERE id=101");
        assertSameValue(false,(new BankReconciliationService($db))->createReconciliation(workflowReconciliation())['success']);
        assertSameValue(true,$db->inTransaction());assertSameValue('Caller work',$db->query('SELECT account_name FROM coop_accounts WHERE id=101')->fetchColumn());
        assertSameValue($before,workflowCount($db,'coop_journal_entries'));assertSameValue(0,workflowCount($db,'coop_bank_reconciliation'));$db->rollBack();
    }finally{if($db->inTransaction()){$db->rollBack();}$db->exec('DROP TRIGGER workflow_bank_audit');}
});
test('successful reconciliation joins caller transaction and is undone by rollback',function () use ($db,$tables) {
    workflowReset($db,$tables);$before=workflowCount($db,'coop_journal_entries');$db->beginTransaction();
    workflowSuccess((new BankReconciliationService($db))->createReconciliation(workflowReconciliation()));assertSameValue(true,$db->inTransaction());
    $db->rollBack();assertSameValue($before,workflowCount($db,'coop_journal_entries'));assertSameValue(0,workflowCount($db,'coop_bank_reconciliation'));assertSameValue('100.00',workflowNet($db,101));
});
test('reopening refuses existing closing journals and downstream openings', function () use ($db,$tables) {
    workflowReset($db,$tables);workflowPost($db,101,103,'0.30');
    $processor=new PeriodClosingProcessor($db);workflowSuccess($processor->closePeriod(1,1));
    $count=workflowCount($db,'coop_journal_entries');
    $result=$processor->reopenPeriod(1,1,'Need a correction');assertSameValue(false,$result['success']);
    assertContains('Controlled reopening/reconciliation required',$result['error']);
    assertSameValue(true,$processor->getPeriodStatus(1)['is_closed']);assertSameValue($count,workflowCount($db,'coop_journal_entries'));
    workflowReset($db,$tables);workflowSuccess($processor->closePeriod(1,1));
    $result=$processor->reopenPeriod(1,1,'No income journal but later opening exists');assertSameValue(false,$result['success']);
    assertContains('Controlled reopening/reconciliation required',$result['error']);
});
test('final close audit failure rolls back closure and copied openings',function () use ($db,$tables) {
    workflowReset($db,$tables);workflowPost($db,101,103,'0.30');$count=workflowCount($db,'coop_journal_entries');
    $db->exec("CREATE TRIGGER workflow_fail_close_audit BEFORE INSERT ON coop_audit_trail FOR EACH ROW BEGIN IF NEW.action_type='close_period' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic final close audit failure';END IF;END");
    try {$result=(new PeriodClosingProcessor($db))->closePeriod(1,1);assertSameValue(false,$result['success']);assertContains('Synthetic final close audit failure',$result['error']);}
    finally {$db->exec('DROP TRIGGER workflow_fail_close_audit');}
    assertSameValue($count,workflowCount($db,'coop_journal_entries'));assertSameValue('0',(string)$db->query('SELECT COUNT(*) FROM coop_period_balances WHERE is_closed=1 OR periodid=2')->fetchColumn());
});
finishTests();


