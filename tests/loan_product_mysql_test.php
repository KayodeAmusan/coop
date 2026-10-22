<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../libs/services/LoanProductService.php';
require_once __DIR__ . '/../includes/loan_consent_helper.php';
if (getenv('COOP_LOAN_PRODUCT_MYSQL_TEST') !== '1') { echo "SKIP isolated product tests: set COOP_LOAN_PRODUCT_MYSQL_TEST=1\n"; exit; }
$db = new PDO('mysql:host=127.0.0.1;port=13307;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('CREATE DATABASE IF NOT EXISTS coop_test_loan_products');
$db->exec('USE coop_test_loan_products');
foreach (['coop_loan_product_versions','coop_loan_products','loan_requests','coop_audit_trail'] as $table) $db->exec('DROP TABLE IF EXISTS '.$table);
$db->exec('CREATE TABLE loan_requests (id INT PRIMARY KEY, requested_amount DECIMAL(15,2), status VARCHAR(30)) ENGINE=InnoDB');
$db->exec("INSERT INTO loan_requests VALUES (1,123.45,'submitted')");
function runLoanProductMigration(PDO $db, string $sql): void {
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,true);
    $stmt=$db->query($sql);
    do { if($stmt->columnCount()) $stmt->fetchAll(); } while($stmt->nextRowset());
    $stmt->closeCursor();
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
}
$accountingSchema=file_get_contents(__DIR__.'/../SETUP_FULL_ACCOUNTING_SYSTEM.sql');
if(!preg_match('/CREATE TABLE IF NOT EXISTS coop_audit_trail\s*\(.*?\) ENGINE=InnoDB[^;]*;/s',$accountingSchema,$auditMatch))throw new RuntimeException('Missing audit fixture');
$db->exec($auditMatch[0]);
$migration=file_get_contents(__DIR__.'/../database/migrations/20261021_loan_product_drafts.sql');
test('draft migration is repeatable and preserves legacy applications',function()use($db,$migration){
    runLoanProductMigration($db,$migration); runLoanProductMigration($db,$migration);
    $row=$db->query('SELECT requested_amount,status,loan_product_version_id,product_terms_snapshot,product_terms_hash FROM loan_requests WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    assertSameValue(['requested_amount'=>'123.45','status'=>'submitted','loan_product_version_id'=>null,'product_terms_snapshot'=>null,'product_terms_hash'=>null],$row);
    assertSameValue(0,(int)$db->query('SELECT COUNT(*) FROM coop_loan_products')->fetchColumn());
});
$service=new LoanProductService($db);
$product=$service->createDraft('SYNTHETIC','Synthetic draft','No approved policies',7);
$version=$service->addDraftVersion($product['id'],['owner_review_required'=>true],null,7);
test('draft versions append without rewriting earlier candidate terms',function()use($db,$service,$product,$version){
    $next=$service->addDraftVersion($product['id'],['owner_review_required'=>true,'candidate_method'=>'undecided'],'Unapproved draft terms',7);
    assertSameValue(1,$version['version_number']); assertSameValue(2,$next['version_number']);
    assertSameValue(2,(int)$db->query('SELECT COUNT(*) FROM coop_loan_product_versions')->fetchColumn());
    $row=$db->query('SELECT configuration_json,terms_text,content_hash FROM coop_loan_product_versions WHERE id='.(int)$version['id'])->fetch(PDO::FETCH_ASSOC);
    assertSameValue($version['content_hash'],LoanProductService::draftHash($row['configuration_json'],$row['terms_text']));
});
test('draft hashes tolerate native JSON key and whitespace normalization',function(){
    assertSameValue(LoanProductService::draftHash('{"b":2,"a":{"y":0,"x":1}}',null),LoanProductService::draftHash('{ "a": { "x": 1, "y": 0 }, "b": 2 }',null));
});
test('draft version savepoint preserves caller rollback',function()use($db,$service,$product){
    $db->beginTransaction(); $service->addDraftVersion($product['id'],[],null,7);
    assertSameValue(true,$db->inTransaction()); $db->rollBack();
    assertSameValue(2,(int)$db->query('SELECT COUNT(*) FROM coop_loan_product_versions')->fetchColumn());
});
test('all new applications remain blocked despite draft ID or spoofed product input',function()use($db,$service,$version){
    foreach([null,0,$version['id'],999]as$id) foreach(['member','nonmember']as$type){
        try{$service->requireApplicationTerms($id,$type);throw new Exception('Unsupported product was enabled');}
        catch(RuntimeException$error){assertSameValue(409,$error->getCode());assertContains('configuration is required',$error->getMessage());}
    }
    assertSameValue(false,$service->applicationAvailability()['applications_enabled']);
    assertSameValue([], $service->applicationAvailability()['products']);
    assertSameValue(1,(int)$db->query('SELECT COUNT(*) FROM loan_requests')->fetchColumn());
});
test('member and staff creation routes contain no unconfigured insertion or legacy rate',function(){
    foreach(['loans/request.php','admin/manual-loan.php']as$path){
        $source=file_get_contents(__DIR__.'/../auth_api/api/'.$path);
        assertContains('requireApplicationTerms(', $source);
        assertSameValue(false,str_contains($source,'INSERT INTO loan_requests'));
        assertSameValue(false,str_contains($source,'intdiv($requestedMinor + 5, 10)'));
    }
});
test('product terms version and immutable snapshot participate in consent hash',function(){
    $loan=['requester_coop_id'=>'borrower','requested_amount'=>'100.00','monthly_repayment'=>'10.00'];
    $legacy=loanConsentTermsHash($loan);
    $snapshot='{"method":"synthetic-fixture-only","duration":10}';
    $loan+=['loan_product_version_id'=>1,'product_terms_snapshot'=>$snapshot,'product_terms_hash'=>hash('sha256',$snapshot)];
    $first=loanConsentTermsHash($loan); assertSameValue(false,$legacy===$first);
    $loan['loan_product_version_id']=2; assertSameValue(false,$first===loanConsentTermsHash($loan));
    $loan['product_terms_snapshot']='changed without matching hash';
    try{loanConsentTermsHash($loan);throw new Exception('Tampered snapshot accepted');}
    catch(RuntimeException$error){assertSameValue(409,$error->getCode());}
});
test('rollback retains nonempty draft audit data and removes only empty storage',function()use($db,$migration){
    $down=file_get_contents(__DIR__.'/../database/migrations/20261021_loan_product_drafts_down.sql');
    runLoanProductMigration($db,$down);
    assertSameValue(2,(int)$db->query('SELECT COUNT(*) FROM coop_loan_product_versions')->fetchColumn());
    $db->exec('DELETE FROM coop_loan_product_versions'); $db->exec('DELETE FROM coop_loan_products');
    $db->exec("UPDATE loan_requests SET product_terms_snapshot='retained evidence' WHERE id=1");
    runLoanProductMigration($db,$down);
    assertSameValue('retained evidence',$db->query('SELECT product_terms_snapshot FROM loan_requests WHERE id=1')->fetchColumn());
    $db->exec('UPDATE loan_requests SET product_terms_snapshot=NULL');
    runLoanProductMigration($db,$down); runLoanProductMigration($db,$down);
    assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_loan_products'")->fetchColumn());
    assertSameValue('123.45',$db->query('SELECT requested_amount FROM loan_requests WHERE id=1')->fetchColumn());
    runLoanProductMigration($db,$migration);
});
finishTests();
