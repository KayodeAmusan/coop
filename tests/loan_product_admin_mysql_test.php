<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../libs/services/LoanProductService.php';
if(getenv('COOP_LOAN_PRODUCT_ADMIN_MYSQL_TEST')!=='1'){echo "SKIP isolated draft admin tests\n";exit;}
function draftAdminTestDb(): PDO {
    return new PDO('mysql:host=127.0.0.1;port=13307;dbname=coop_test_loan_product_admin;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
}
if(($argv[1]??'')==='worker'){
    $service=new LoanProductService(draftAdminTestDb());
    $out=$service->runAdminCommand('create_draft',['code'=>'RACE','name'=>'Concurrent synthetic','description'=>'test'],7,'same-race-key');
    echo json_encode($out);exit;
}
$server=new PDO('mysql:host=127.0.0.1;port=13307','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS coop_test_loan_product_admin');$db=draftAdminTestDb();
foreach(['coop_loan_product_commands','coop_loan_product_versions','coop_loan_products','loan_requests','coop_audit_trail']as$table)$db->exec('DROP TABLE IF EXISTS '.$table);
$db->exec('CREATE TABLE loan_requests(id INT PRIMARY KEY) ENGINE=InnoDB');
function draftAdminRunSql(PDO$db,string$sql):void{$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,true);$stmt=$db->query($sql);do{if($stmt->columnCount())$stmt->fetchAll();}while($stmt->nextRowset());$stmt->closeCursor();$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);}
foreach(['20261021_loan_product_drafts.sql','20261021_loan_product_commands.sql','20261021_loan_product_commands.sql']as$file)draftAdminRunSql($db,file_get_contents(__DIR__.'/../database/migrations/'.$file));
$schema=file_get_contents(__DIR__.'/../SETUP_FULL_ACCOUNTING_SYSTEM.sql');preg_match('/CREATE TABLE IF NOT EXISTS coop_audit_trail\s*\(.*?\) ENGINE=InnoDB[^;]*;/s',$schema,$match);$db->exec($match[0]);
$service=new LoanProductService($db);$create=['code'=>'ADMIN_SYNTHETIC','name'=>'Unapproved draft','description'=>'Synthetic test only'];
$calc=['principal'=>'1000.00','method'=>'interest_free','installment_count'=>3,'rounding_mode'=>'half_up','residual_treatment'=>'adjust_final_installment','fees'=>['handling'=>'none']];
test('audited create is replayed once and changed payload conflicts',function()use($db,$service,$create){
    $first=$service->runAdminCommand('create_draft',$create,7,'create-1');$replay=$service->runAdminCommand('create_draft',$create,7,'create-1');
    assertSameValue($first['id'],$replay['id']);assertSameValue(false,$first['replayed']);assertSameValue(true,$replay['replayed']);
    assertSameValue(1,(int)$db->query('SELECT COUNT(*) FROM coop_audit_trail')->fetchColumn());
    $changed=$create;$changed['name']='Changed';try{$service->runAdminCommand('create_draft',$changed,7,'create-1');throw new Exception('Conflict accepted');}catch(RuntimeException$e){assertSameValue(409,$e->getCode());}
});
$productId=(int)$db->query("SELECT id FROM coop_loan_products WHERE product_code='ADMIN_SYNTHETIC'")->fetchColumn();
$save=['product_id'=>$productId,'calculation_inputs'=>$calc,'terms'=>'Unapproved terms for owner review'];
test('version command saves structured candidate and immutable retry without extra audit',function()use($db,$service,$save,$productId){
    $first=$service->runAdminCommand('save_version',$save,7,'save-1');$again=$service->runAdminCommand('save_version',$save,7,'save-1');assertSameValue($first['id'],$again['id']);assertSameValue(true,$again['replayed']);
    assertSameValue(2,(int)$db->query('SELECT COUNT(*) FROM coop_audit_trail')->fetchColumn());
    $versions=$service->listVersions($productId,1,10);assertSameValue(1,$versions['total']);assertSameValue('interest_free',$versions['items'][0]['configuration']['calculation_inputs']['method']);assertSameValue('draft',$versions['items'][0]['status']);
});
test('audit failure rolls command and creation back, same reference can safely retry',function()use($db,$service){
    $db->exec('RENAME TABLE coop_audit_trail TO coop_audit_trail_unavailable');
    $payload=['code'=>'AUDIT_FAIL','name'=>'Rollback check','description'=>'Synthetic'];
    try{$service->runAdminCommand('create_draft',$payload,7,'audit-retry');throw new Exception('Audit failure accepted');}catch(PDOException$expected){}finally{$db->exec('RENAME TABLE coop_audit_trail_unavailable TO coop_audit_trail');}
    assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM coop_loan_products WHERE product_code='AUDIT_FAIL'")->fetchColumn());
    assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM coop_loan_product_commands WHERE idempotency_key='audit-retry'")->fetchColumn());
    assertSameValue(false,$service->runAdminCommand('create_draft',$payload,7,'audit-retry')['replayed']);
});
test('audit failure on immutable version leaves no row, command or consumed version number',function()use($db,$service,$save,$productId){
    $db->exec('RENAME TABLE coop_audit_trail TO coop_audit_trail_unavailable');
    try{$service->runAdminCommand('save_version',$save,7,'version-audit-retry');throw new Exception('Audit failure accepted');}catch(PDOException$expected){}finally{$db->exec('RENAME TABLE coop_audit_trail_unavailable TO coop_audit_trail');}
    assertSameValue(1,$service->listVersions($productId,1,10)['total']);
    assertSameValue(2,$service->runAdminCommand('save_version',$save,7,'version-audit-retry')['version_number']);
});
test('nested command never commits caller and rolls back with caller',function()use($db,$service){
    $db->beginTransaction();$service->runAdminCommand('create_draft',['code'=>'OUTER_ROLLBACK','name'=>'Caller-owned','description'=>'test'],7,'outer-rollback');assertSameValue(true,$db->inTransaction());$db->rollBack();
    assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM coop_loan_products WHERE product_code='OUTER_ROLLBACK'")->fetchColumn());assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM coop_loan_product_commands WHERE idempotency_key='outer-rollback'")->fetchColumn());
});
test('pure preview cannot write product, version or audit records',function()use($db,$service,$calc){
    $before=(int)$db->query('SELECT COUNT(*) FROM coop_audit_trail')->fetchColumn();assertSameValue('1000.00',$service->preview($calc)['total_repayable']);assertSameValue($before,(int)$db->query('SELECT COUNT(*) FROM coop_audit_trail')->fetchColumn());
});
test('pagination and injected actor fields are rejected',function()use($service,$create){
    foreach([[0,10],[1,51],[10001,1]]as[$page,$limit]){try{$service->listDrafts($page,$limit);throw new Exception('Bad pagination accepted');}catch(InvalidArgumentException$expected){}}
    $payload=$create;$payload['created_by_user_id']=999;try{$service->runAdminCommand('create_draft',$payload,7,'actor-injection');throw new Exception('Actor injection accepted');}catch(InvalidArgumentException$expected){}
});
test('concurrent same-key commands create one audited product',function()use($db){
    $workers=[];for($i=0;$i<3;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('No worker');fclose($pipes[0]);$workers[]=[$proc,$pipes];}
    $ids=[];foreach($workers as[$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);assertSameValue(0,proc_close($proc));$result=json_decode($out,true,512,JSON_THROW_ON_ERROR);$ids[]=$result['id'];}
    assertSameValue(1,count(array_unique($ids)));assertSameValue(1,(int)$db->query("SELECT COUNT(*) FROM coop_loan_products WHERE product_code='RACE'")->fetchColumn());assertSameValue(1,(int)$db->query("SELECT COUNT(*) FROM coop_audit_trail WHERE table_name='coop_loan_products' AND record_id=".(int)$ids[0])->fetchColumn());
});
test('endpoint authenticates trusted numeric staff and checks origin before database',function(){
    $source=file_get_contents(__DIR__.'/../api/loan_product_drafts.php');
    $dbPosition=strpos($source,'getDatabaseConfig');
    foreach(['findAdminAccessError($_SESSION)','A valid authenticated staff account','findAdminMutationOriginError($_SERVER)']as$guard){$position=strpos($source,$guard);assertSameValue(true,$position!==false&&$position<$dbPosition);}
    assertSameValue(false,str_contains($source,"$"."input['created_by_user_id']"));assertSameValue(false,str_contains($source,'$error->getMessage() :'));
    assertContains("'Unable to complete the draft operation.",$source);assertContains("'draft_only' => true",$source);assertContains("'applications_enabled' => false",$source);
});
test('schema rollback retains retry evidence and only removes empty command storage',function()use($db){
    $commandsDown=file_get_contents(__DIR__.'/../database/migrations/20261021_loan_product_commands_down.sql');
    draftAdminRunSql($db,$commandsDown);assertSameValue(true,(int)$db->query('SELECT COUNT(*) FROM coop_loan_product_commands')->fetchColumn()>0);
    $db->exec('DELETE FROM coop_loan_product_versions');$db->exec('DELETE FROM coop_loan_products');
    draftAdminRunSql($db,file_get_contents(__DIR__.'/../database/migrations/20261021_loan_product_drafts_down.sql'));
    assertSameValue(1,(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_loan_products'")->fetchColumn());
    $db->exec('DELETE FROM coop_loan_product_commands');draftAdminRunSql($db,$commandsDown);draftAdminRunSql($db,$commandsDown);
    assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_loan_product_commands'")->fetchColumn());
});
finishTests();
