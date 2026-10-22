<?php
require_once __DIR__.'/helpers.php';
$path=sys_get_temp_dir().'/coop-balance-'.bin2hex(random_bytes(8));
foreach(['api','includes','Connections','libs/services'] as $dir) mkdir($path.'/'.$dir,0700,true);
copy(__DIR__.'/../api/get_book_balance.php',$path.'/api/get_book_balance.php');
copy(__DIR__.'/../includes/admin_access_helper.php',$path.'/includes/admin_access_helper.php');
file_put_contents($path.'/Connections/coop.php','<?php $coop=null; $database=null; file_put_contents(__DIR__."/../touched","yes");');
file_put_contents($path.'/libs/services/AccountBalanceCalculator.php','<?php class AccountBalanceCalculator {function __construct($a,$b){} function getAccountBalance($a,$b){return json_decode(getenv("BOOK_RESULT"),true);}}');
file_put_contents($path.'/run.php','<?php session_save_path(__DIR__); session_id("fixture"); session_start(); $_SESSION=["SESS_MEMBER_ID"=>"1","role"=>getenv("BOOK_ROLE")]; session_write_close(); $_GET=["account_id"=>getenv("BOOK_ACCOUNT"),"periodid"=>1]; require __DIR__."/api/get_book_balance.php";');
function runBook($role,$account,$data){
    global $path;
    if(is_file($path.'/touched'))unlink($path.'/touched');
    putenv('BOOK_ROLE='.$role);putenv('BOOK_ACCOUNT='.$account);putenv('BOOK_RESULT='.json_encode($data));
    $p=proc_open([PHP_BINARY,$path.'/run.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    assertSameValue(0,proc_close($p));assertSameValue('',$err);
    return [json_decode($out,true,512,JSON_THROW_ON_ERROR),is_file($path.'/touched')];
}
try{
test('member denied before database access',function(){
    [$r,$t]=runBook('Member',1,[]);assertSameValue(false,$r['success']);assertSameValue(false,$t);
});
test('administrator receives canonical decimals',function(){
    [$r]=runBook('Admin',1,['balance'=>0.01,'debit'=>0.03,'credit'=>0.02,'balance_decimal'=>'0.01','debit_decimal'=>'0.03','credit_decimal'=>'0.02']);
    assertSameValue(true,$r['success']);assertSameValue('0.01',$r['balance_decimal']);
});
test('calculator failure is not successful zero',function(){
    [$r]=runBook('Admin',1,['balance'=>0,'error'=>'private details']);assertSameValue(false,$r['success']);assertSameValue('Book balance is unavailable',$r['error']);assertSameValue(false,isset($r['balance']));
});
test('malformed account is rejected',function(){[$r]=runBook('Admin','1junk',[]);assertSameValue(false,$r['success']);});
}finally{
foreach(['api/get_book_balance.php','includes/admin_access_helper.php','Connections/coop.php','libs/services/AccountBalanceCalculator.php','run.php','touched','sess_fixture'] as $file)if(is_file($path.'/'.$file))unlink($path.'/'.$file);
foreach(['libs/services','libs','api','includes','Connections'] as $dir)rmdir($path.'/'.$dir);
rmdir($path);
}
finishTests();
