<?php
require_once __DIR__.'/helpers.php';
$root=sys_get_temp_dir().'/coop-users-boundary-'.bin2hex(random_bytes(6));
foreach(['api','includes','config','libs/services'] as $d)mkdir($root.'/'.$d,0700,true);
copy(__DIR__.'/../api/users.php',$root.'/api/users.php');
copy(__DIR__.'/../libs/services/CooperativeAccessService.php',$root.'/libs/services/CooperativeAccessService.php');
file_put_contents($root.'/config/EnvConfig.php','<?php echo json_encode(["database_reached"=>true]); exit;');
file_put_contents($root.'/run.php','<?php session_save_path(__DIR__); session_id("fixture"); session_start(); $_SESSION=json_decode(getenv("USERS_SESSION"),true); $_SERVER["REQUEST_METHOD"]=getenv("USERS_METHOD"); $_SERVER["HTTP_HOST"]="coop.test"; $_SERVER["HTTP_ORIGIN"]=getenv("USERS_ORIGIN"); require __DIR__."/api/users.php";');
try {
test('users API fails closed before database and allows only protected safe reads',function()use($root){
foreach([
 [[], 'GET','http://coop.test',false],
 [['SESS_MEMBER_ID'=>1,'role'=>'User'],'GET','http://coop.test',true],
 [['SESS_MEMBER_ID'=>'bad','role'=>'Admin'],'GET','http://coop.test',false],
 [['SESS_MEMBER_ID'=>1,'role'=>'Admin'],'POST','https://other.test',false],
 [['SESS_MEMBER_ID'=>1,'role'=>'Admin'],'POST','http://coop.test',false],
 [['SESS_MEMBER_ID'=>1,'role'=>'Admin'],'PUT','http://coop.test',false],
 [['SESS_MEMBER_ID'=>1,'role'=>'Admin'],'DELETE','http://coop.test',false],
 [['SESS_MEMBER_ID'=>1,'role'=>'Admin'],'GET','http://coop.test',true]
] as [$session,$method,$origin,$expected]) {
putenv('USERS_SESSION='.json_encode($session));putenv('USERS_METHOD='.$method);putenv('USERS_ORIGIN='.$origin);
$p=proc_open([PHP_BINARY,$root.'/run.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);assertSameValue(0,proc_close($p));assertSameValue('',$err);
$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);assertSameValue($expected,$r['database_reached']??false);
}
});
test('users read projection cannot expose password fields or preserve dormant unsafe writes',function(){
$s=file_get_contents(__DIR__.'/../api/users.php');$service=file_get_contents(__DIR__.'/../libs/services/CooperativeAccessService.php');
assertSameValue(false,str_contains($s,'SELECT *'));
assertSameValue(false,str_contains($s,'INSERT INTO tblusers'));
assertSameValue(false,str_contains($s,'DELETE FROM tblusers'));
assertSameValue(true,str_contains($service,'SELECT user_id,Username,CompleteName,Email,AdminType,Status,CreatedDate'));
});
} finally {
foreach(['api/users.php','libs/services/CooperativeAccessService.php','config/EnvConfig.php','run.php','sess_fixture'] as $f)if(is_file($root.'/'.$f))unlink($root.'/'.$f);
foreach(['api','includes','config','libs/services','libs'] as $d)rmdir($root.'/'.$d);rmdir($root);
foreach(['USERS_SESSION','USERS_METHOD','USERS_ORIGIN']as$e)putenv($e);
}
finishTests();
