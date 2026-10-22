<?php
require_once __DIR__.'/helpers.php';
$root=sys_get_temp_dir().'/coop-financial-boundary-'.bin2hex(random_bytes(6));
foreach(['api','includes','Connections'] as $d)mkdir($root.'/'.$d,0700,true);
$routes=['create_journal_entry.php','close_period.php','create_bank_reconciliation.php','reverse_transaction.php','masterReport.php'];
foreach($routes as $r)copy(__DIR__.'/../api/'.$r,$root.'/api/'.$r);
foreach(['admin_access_helper.php','session_helper.php'] as $r)copy(__DIR__.'/../includes/'.$r,$root.'/includes/'.$r);
file_put_contents($root.'/Connections/coop.php','<?php echo json_encode(["database_reached"=>true]); exit;');
file_put_contents($root.'/run.php','<?php session_save_path(__DIR__); session_id("fixture"); session_start(); $_SESSION=["SESS_MEMBER_ID"=>"1","role"=>getenv("BOUNDARY_ROLE")]; session_write_close(); $_POST=json_decode(getenv("BOUNDARY_POST")?:\'{"idempotency_key":"boundary-fixture"}\',true); $_SERVER["REQUEST_METHOD"]=getenv("BOUNDARY_METHOD"); $_SERVER["HTTP_HOST"]="coop.test"; $_SERVER["HTTP_ORIGIN"]=getenv("BOUNDARY_ORIGIN"); require __DIR__."/api/".getenv("BOUNDARY_ROUTE");');
try{
foreach($routes as $route)test($route.' checks permission, method and origin before database',function()use($route,$root){
foreach([['Member','POST','http://coop.test',false],['Admin','GET','http://coop.test',false],['Admin','POST','https://other.test',false],['Admin','POST','http://coop.test',true]] as [$role,$method,$origin,$expected]){
putenv('BOUNDARY_ROUTE='.$route);putenv('BOUNDARY_ROLE='.$role);putenv('BOUNDARY_METHOD='.$method);putenv('BOUNDARY_ORIGIN='.$origin);
$p=proc_open([PHP_BINARY,$root.'/run.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);assertSameValue(0,proc_close($p));assertSameValue('',$err);
$response=json_decode($out,true,512,JSON_THROW_ON_ERROR);assertSameValue($expected,$response['database_reached']??false);
if(!$expected)assertSameValue(false,$response['success']);
}
});
test('bank reconciliation rejects missing, malformed and spoofed-actor commands before database',function()use($root){
    putenv('BOUNDARY_ROUTE=create_bank_reconciliation.php');putenv('BOUNDARY_ROLE=Admin');putenv('BOUNDARY_METHOD=POST');putenv('BOUNDARY_ORIGIN=http://coop.test');
    foreach([[],['idempotency_key'=>''],['idempotency_key'=>[]],['idempotency_key'=>'boundary-fixture','reconciled_by'=>999]]as$payload){
        putenv('BOUNDARY_POST='.json_encode($payload));$p=proc_open([PHP_BINARY,$root.'/run.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);assertSameValue(0,proc_close($p));assertSameValue('',$err);$response=json_decode($out,true,512,JSON_THROW_ON_ERROR);assertSameValue(false,$response['database_reached']??false);assertSameValue(false,$response['success']);
    }
    putenv('BOUNDARY_POST');
});
}finally{
foreach($routes as $r)unlink($root.'/api/'.$r);
foreach(['admin_access_helper.php','session_helper.php'] as $r)unlink($root.'/includes/'.$r);
foreach(['Connections/coop.php','run.php','sess_fixture'] as $r)if(is_file($root.'/'.$r))unlink($root.'/'.$r);
foreach(['api','includes','Connections'] as $d)rmdir($root.'/'.$d);rmdir($root);
}
finishTests();
