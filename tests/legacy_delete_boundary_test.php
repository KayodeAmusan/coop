<?php
require_once __DIR__.'/helpers.php';
$fixture=sys_get_temp_dir().'/coop-delete-'.bin2hex(random_bytes(6));
foreach(['classes','includes','Connections']as$d)mkdir($fixture.'/'.$d,0700,true);
copy(__DIR__.'/../classes/delete.php',$fixture.'/classes/delete.php');
foreach(['session_helper.php','admin_access_helper.php']as$f)copy(__DIR__.'/../includes/'.$f,$fixture.'/includes/'.$f);
file_put_contents($fixture.'/Connections/coop.php','<?php throw new RuntimeException("Unsafe deletion touched database");');
file_put_contents($fixture.'/run.php','<?php session_save_path(__DIR__);session_id("fixture");session_start();$_SESSION=["SESS_MEMBER_ID"=>"1","role"=>getenv("DELETE_ROLE")];$_POST=["myarray"=>["member,1"]];require __DIR__."/classes/delete.php";');
try{
foreach(['Member','Admin']as$role)test('legacy deletion denied without database access for '.$role,function()use($fixture,$role){
putenv('DELETE_ROLE='.$role);$p=proc_open([PHP_BINARY,$fixture.'/run.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);assertSameValue(0,proc_close($p));assertSameValue('',$err);
$result=json_decode($out,true,512,JSON_THROW_ON_ERROR);assertSameValue(false,$result['success']);if($role==='Admin')assertContains('No records were deleted',$result['message']);
});
}finally{
foreach(['classes/delete.php','includes/session_helper.php','includes/admin_access_helper.php','Connections/coop.php','run.php','sess_fixture']as$f)if(is_file($fixture.'/'.$f))unlink($fixture.'/'.$f);
foreach(['classes','includes','Connections']as$d)rmdir($fixture.'/'.$d);rmdir($fixture);
}
finishTests();
