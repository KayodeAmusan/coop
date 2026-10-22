<?php
if (PHP_SAPI !== 'cli' || getenv('COOP_PAYROLL_MYSQL_TEST') !== '1') exit(2);
require_once __DIR__.'/../includes/payroll_helper.php';
require_once __DIR__.'/../libs/services/AccountingEngine.php';
$source=file_get_contents(__DIR__.'/../classes/process.php');
eval(substr($source,strpos($source,'function isTransactionCompleted(')));
function checkPayroll($ok,$label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function payrollDb() { return new PDO('mysql:host=127.0.0.1;port=13307;dbname=coop_test_payroll;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
if (($argv[1]??'')==='worker') {
 $db=payrollDb();$db->beginTransaction();lockPayrollRun($db,1);
 $db->query("SELECT CoopID FROM tblemployees WHERE CoopID='m' FOR UPDATE")->fetch();
 if(isTransactionCompleted($db,'m',1)) {$db->rollBack();echo 'duplicate';exit;}
 usleep(200000);processSavingsAndShares($db,'m',1,101,500000,500000);
 createMemberJournalEntry(new AccountingEngine($db),$db,'m',1);$db->commit();echo 'created';exit;
}
$server=new PDO('mysql:host=127.0.0.1;port=13307;charset=utf8mb4','root','');
$server->exec('CREATE DATABASE IF NOT EXISTS coop_test_payroll');$db=payrollDb();
$tables=['coop_payroll_unallocated','coop_payroll_configuration','coop_journal_sequences','coop_journal_entry_lines','coop_period_balances','coop_audit_trail','coop_journal_entries','coop_accounts','tbpayrollperiods','tblemployees','tbl_mastertransact','tbl_savings','tbl_shares','tbl_loans','tbl_interest','tbl_loanrepayment'];
foreach($tables as $table)$db->exec("DROP TABLE IF EXISTS $table");
$db->exec('CREATE TABLE tbpayrollperiods(id INT PRIMARY KEY,PayrollPeriod VARCHAR(100)) ENGINE=InnoDB');
$db->exec("INSERT INTO tbpayrollperiods VALUES(1,'October 2026')");
$db->exec('CREATE TABLE tblemployees(CoopID VARCHAR(50) PRIMARY KEY,FirstName VARCHAR(50),LastName VARCHAR(50)) ENGINE=InnoDB');
$db->exec("INSERT INTO tblemployees VALUES('m','Test','Member')");
$schema=file_get_contents(__DIR__.'/../SETUP_FULL_ACCOUNTING_SYSTEM.sql');
foreach(['coop_accounts','coop_journal_entries','coop_journal_entry_lines','coop_period_balances','coop_audit_trail'] as $table){
 if(!preg_match('/CREATE TABLE IF NOT EXISTS '.$table.'\s*\(.*?ENGINE=InnoDB[^;]*;/s',$schema,$sql))throw new RuntimeException('Missing schema');
 $db->exec($sql[0]);
}
$db->exec(file_get_contents(__DIR__.'/../database/migrations/20261021_ledger_sequences.sql'));
foreach(['1102','4101','3201','3101','4299','4102','1110','1120','test-holding'] as $i=>$code)$db->exec("INSERT INTO coop_accounts(id,account_code,account_name,account_type,normal_balance)VALUES(".($i+101).",'$code','Synthetic','asset','debit')");
$holdingMigration=file_get_contents(__DIR__.'/../database/migrations/20261021_payroll_holding.sql');
$db->exec($holdingMigration);$db->exec($holdingMigration);
$db->beginTransaction();
try { payrollHoldingConfiguration($db);throw new LogicException('Missing setup accepted'); }
catch(RuntimeException $e){checkPayroll(strpos($e->getMessage(),'during setup')!==false,'missing holding setup fails closed');}
$db->rollBack();
$db->beginTransaction();configurePayrollHoldingAccount($db,109,1);$db->commit();
checkPayroll((int)$db->query('SELECT holding_account_id FROM coop_payroll_configuration')->fetchColumn()===109,'administrator explicitly selects existing holding account');
foreach(['is_active=0','is_control_account=1'] as $invalidAccount){
 $db->exec('UPDATE coop_accounts SET '.$invalidAccount.' WHERE id=108');$db->beginTransaction();
 try{configurePayrollHoldingAccount($db,108,1);throw new LogicException('Invalid holding account accepted');}
 catch(InvalidArgumentException $e){checkPayroll(true,'inactive/control holding account rejected');$db->rollBack();}
 $db->exec('UPDATE coop_accounts SET is_active=1,is_control_account=0 WHERE id=108');
}
$columns='';foreach(['EntryFee','savingsAmount','sharesAmount','DevLevy','Stationery','InterestPaid','loanRepayment','CommodityRepayment','Commodity','capitalized_interest','loan'] as $column)$columns.=",$column DECIMAL(15,2) NULL";
$db->exec("CREATE TABLE tbl_mastertransact(id INT AUTO_INCREMENT PRIMARY KEY,CoopID VARCHAR(50),TransactionPeriod INT $columns) ENGINE=InnoDB");
foreach(['tbl_savings'=>['DeductionPeriod','AmountPaid'],'tbl_shares'=>['SharesPeriod','sharesAmount'],'tbl_loans'=>['LoanPeriod','LoanAmount'],'tbl_interest'=>['InterestPeriod','IntAmount'],'tbl_loanrepayment'=>['LoanRepaymentPeriod','Repayment']] as $table=>$f)$db->exec("CREATE TABLE $table(id INT AUTO_INCREMENT PRIMARY KEY,CoopID VARCHAR(50),{$f[0]} INT,{$f[1]} DECIMAL(15,2),remarks VARCHAR(100)) ENGINE=InnoDB");
checkPayroll(payrollRate('0.123456')===123456,'six-place rates exact');
$db->exec('DROP TABLE IF EXISTS tbl_loanapproval');
$db->exec('CREATE TABLE tbl_loanapproval(id INT AUTO_INCREMENT PRIMARY KEY,CoopID VARCHAR(50),period INT,LoanAmount DECIMAL(15,2)) ENGINE=InnoDB');
$db->exec("INSERT INTO tbl_loanapproval(CoopID,period,LoanAmount) VALUES('m',1,100.00)");
$db->beginTransaction();
try {
    processSavingsAndShares($db,'m',1,100,500000,500000);
    processPendingLoans($db,'m',1);
    throw new LogicException('Unverified approval posted as disbursement');
} catch (RuntimeException $e) {
    checkPayroll(strpos($e->getMessage(),'not proof of payment')!==false,'unlinked approval cannot become paid loan');
    $db->rollBack();
}
checkPayroll((int)$db->query('SELECT COUNT(*) FROM tbl_mastertransact')->fetchColumn()===0 && (int)$db->query('SELECT COUNT(*) FROM tbl_loans')->fetchColumn()===0,'unlinked approval failure rolls back earlier contribution writes');
$db->exec('DELETE FROM tbl_loanapproval');
foreach (['0.1234567','-0.1','1.000001','invalid'] as $rate) {
 try { payrollRate($rate); throw new LogicException('Invalid rate accepted'); }
 catch (InvalidArgumentException $e) { checkPayroll(true,'invalid rate rejected'); }
}
try { processSavingsAndShares($db,'m',1,100,700000,700000); throw new LogicException('Excess allocation accepted'); }
catch (RuntimeException $e) { checkPayroll(strpos($e->getMessage(),'exceed')!==false,'excess allocation rejected'); }

checkPayroll(payrollRateAmount(999999999999999,1000000)===999999999999999,'maximum amount avoids overflow');
$engine=new AccountingEngine($db);$db->beginTransaction();
processSavingsAndShares($db,'m',1,1,500000,500000);
checkPayroll($db->query('SELECT sharesAmount FROM tbl_shares')->fetchColumn()==='0.00','fractional shares remain unallocated');
checkPayroll($db->query('SELECT AmountPaid FROM tbl_savings')->fetchColumn()==='0.00','fractional savings remain unallocated');
checkPayroll($db->query('SELECT amount FROM coop_payroll_unallocated')->fetchColumn()==='0.01','rounding residue held for review');
recordPayrollUnallocated($db,'m',1,1);
checkPayroll((int)$db->query('SELECT COUNT(*) FROM coop_payroll_unallocated')->fetchColumn()===1,'identical holding retry has one source record');
insertTransaction($db,'tbl_mastertransact','m',1,'InterestPaid',20);
createMemberJournalEntry($engine,$db,'m',1);
checkPayroll($db->query('SELECT total_amount FROM coop_journal_entries')->fetchColumn()==='0.21','all sparse transaction rows journaled');
checkPayroll($db->query('SELECT status FROM coop_journal_entries')->fetchColumn()==='posted','journal posted');
$held=$db->query('SELECT * FROM coop_payroll_unallocated')->fetch(PDO::FETCH_ASSOC);
checkPayroll($held['coop_id']==='m' && (int)$held['period_id']===1 && $held['source_type']==='payroll' && $held['journal_entry_id']!==null,'held amount has member period source and journal trace');
checkPayroll($db->query('SELECT credit_amount FROM coop_journal_entry_lines WHERE account_id=109')->fetchColumn()==='0.01','holding credit matches traced remainder');
try { createMemberJournalEntry($engine,$db,'m',1);throw new LogicException('Held funds posted twice'); }
catch(RuntimeException $e){checkPayroll(strpos($e->getMessage(),'already journaled')!==false,'linked holding funds cannot be journaled twice');}

checkPayroll($db->query("SELECT reference_id FROM coop_journal_entry_lines WHERE reference_type='member' LIMIT 1")->fetchColumn()==='m','alphanumeric member reference retained');
checkPayroll($db->inTransaction(),'outer transaction preserved');$db->rollBack();
checkPayroll((int)$db->query('SELECT COUNT(*) FROM coop_payroll_unallocated')->fetchColumn()===0,'outer rollback removes held funds');
checkPayroll((int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn()===0,'outer rollback removes journal');
$db->beginTransaction();processSavingsAndShares($db,'m',1,101,200000,200000);
checkPayroll($db->query('SELECT amount FROM coop_payroll_unallocated')->fetchColumn()==='0.61','rate underallocation and fractions held exactly');
createMemberJournalEntry($engine,$db,'m',1);
checkPayroll($db->query('SELECT total_amount FROM coop_journal_entries')->fetchColumn()==='1.01','allocated plus held journal conserves full deduction');$db->rollBack();
$db->beginTransaction();processLoanRepayment($db,'m',1,0,10000,10000);createMemberJournalEntry($engine,$db,'m',1);
checkPayroll($db->query('SELECT total_amount FROM coop_journal_entries')->fetchColumn()==='1.00','capitalized-only journal has no zero bank line');$db->rollBack();
$db->exec('UPDATE coop_accounts SET is_active=0 WHERE id=109');$db->beginTransaction();
try{processSavingsAndShares($db,'m',1,1,500000,500000);throw new LogicException('Inactive configuration accepted');}
catch(RuntimeException $e){checkPayroll(strpos($e->getMessage(),'unavailable')!==false,'inactive configured holding account blocks allocation');$db->rollBack();}
$db->exec('UPDATE coop_accounts SET is_active=1 WHERE id=109');
$db->beginTransaction();recordPayrollUnallocated($db,'m',1,1);
try{recordPayrollUnallocated($db,'m',1,2);throw new LogicException('Conflicting retry accepted');}
catch(RuntimeException $e){checkPayroll(strpos($e->getMessage(),'conflicts')!==false,'holding retry amount conflict rejected');$db->rollBack();}
$db->exec("CREATE TRIGGER payroll_link_fail BEFORE UPDATE ON coop_payroll_unallocated FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic link failure'");
$db->beginTransaction();
try{processSavingsAndShares($db,'m',1,1,500000,500000);createMemberJournalEntry($engine,$db,'m',1);throw new LogicException('Trace linkage failure swallowed');}
catch(PDOException $e){checkPayroll(strpos($e->getMessage(),'synthetic link failure')!==false,'holding journal linkage failure propagates');$db->rollBack();}
$db->exec('DROP TRIGGER payroll_link_fail');
checkPayroll((int)$db->query('SELECT COUNT(*) FROM coop_payroll_unallocated')->fetchColumn()===0 && (int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn()===0,'link failure rolls back posted journal and held trace together');
$db->exec("CREATE TRIGGER payroll_fail BEFORE INSERT ON coop_audit_trail FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'");
$db->beginTransaction();try{processSavingsAndShares($db,'m',1,1,500000,500000);createMemberJournalEntry($engine,$db,'m',1);throw new LogicException('Failure swallowed');}
catch(RuntimeException $e){checkPayroll(strpos($e->getMessage(),'Journal posting failed')!==false,'journal failure propagates');$db->rollBack();}
$db->exec('DROP TRIGGER payroll_fail');
checkPayroll((int)$db->query('SELECT COUNT(*) FROM coop_payroll_unallocated')->fetchColumn()===0,'journal failure rolls back unallocated trace');
checkPayroll((int)$db->query('SELECT COUNT(*) FROM tbl_mastertransact')->fetchColumn()===0,'failed journal leaves no financial writes');
$db->beginTransaction();try{insertTransaction($db,'missing_payroll_table','m',1,'Amount',100);throw new LogicException('Failure swallowed');}
catch(PDOException $e){checkPayroll(true,'insert failure propagates');$db->rollBack();}
$workers=[];for($i=0;$i<2;$i++){$process=proc_open([PHP_BINARY,__FILE__,'worker'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$process,$pipes];}
$results=[];foreach($workers as [$process,$pipes]){$results[]=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);checkPayroll(proc_close($process)===0,'concurrent worker: '.$error);}
sort($results);checkPayroll($results===['created','duplicate'],'same period retry serialized');
checkPayroll((int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn()===1,'exactly one journal persisted');

checkPayroll((int)$db->query('SELECT COUNT(*) FROM coop_payroll_unallocated WHERE journal_entry_id IS NOT NULL')->fetchColumn()===1,'concurrent payroll persists one linked holding record');
$referenceMigration=file_get_contents(__DIR__.'/../database/migrations/20261021_journal_reference_ids.sql');
$db->exec($referenceMigration);$db->exec($referenceMigration);
checkPayroll($db->query("SELECT reference_id FROM coop_journal_entry_lines WHERE reference_type='member' LIMIT 1")->fetchColumn()==='m','reference migration replay preserves alphanumeric references');
$db->exec($holdingMigration);
checkPayroll($db->query('SELECT amount FROM coop_payroll_unallocated')->fetchColumn()==='0.01','holding migration replay preserves held funds');
