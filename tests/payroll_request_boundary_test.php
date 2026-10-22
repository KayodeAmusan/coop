<?php
require_once __DIR__.'/helpers.php';
if (($argv[1]??'')==='worker') {
 $fixture=$argv[2];$request=json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR);
 $_SERVER=array_merge(['REQUEST_METHOD'=>'POST','HTTP_HOST'=>'coop.test','HTTP_ORIGIN'=>'http://coop.test'],$request['server']??[]);
 foreach($request['unset']??[]as$key)unset($_SERVER[$key]);
 $_POST=$request['post']??['PeriodID'=>'1','member_id'=>'COOP-001'];$_GET=$request['get']??[];
 session_save_path($fixture.'/sessions');session_id('payrollboundary'.bin2hex(random_bytes(6)));session_start();$_SESSION=$request['session']??[];
 http_response_code(200);ob_start();register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'body'=>ob_get_clean()],JSON_THROW_ON_ERROR);});
 chdir($fixture);require $fixture.(($request['page']??false)?'/payprocess.php':'/classes/process.php');exit;
}
$fixture=sys_get_temp_dir().'/coop_payroll_boundary_'.bin2hex(random_bytes(6));
foreach(['','/classes','/includes','/Connections','/sessions']as$folder)mkdir($fixture.$folder,0700);
$repo=dirname(__DIR__);copy($repo.'/classes/process.php',$fixture.'/classes/process.php');copy($repo.'/payprocess.php',$fixture.'/payprocess.php');
foreach(['admin_access_helper.php','session_helper.php']as$file)file_put_contents($fixture.'/includes/'.$file,'<?php require_once '.var_export($repo.'/includes/'.$file,true).';');
file_put_contents($fixture.'/Connections/coop.php','<?php exit("DB_TOUCHED");');
file_put_contents($fixture.'/includes/header.php','<?php exit("HEADER_TOUCHED");');
function payrollBoundaryRequest($fixture,array$request){
 $process=proc_open([PHP_BINARY,__FILE__,'worker',$fixture,base64_encode(json_encode($request,JSON_THROW_ON_ERROR))],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Cannot run payroll boundary fixture');
 $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 if(proc_close($process)!==0)throw new RuntimeException($error.$output);
 return json_decode($output,true,512,JSON_THROW_ON_ERROR);
}
$admin=['SESS_MEMBER_ID'=>'7','role'=>'Admin'];
test('unauthenticated member and deceptive role cannot reach payroll database',function()use($fixture){
 foreach([[],['SESS_MEMBER_ID'=>'7','role'=>'Member'],['SESS_MEMBER_ID'=>'7','role'=>'notAdmin']]as$session){
  $response=payrollBoundaryRequest($fixture,['session'=>$session]);assertSameValue(403,$response['status']);assertContains('ERROR:',$response['body']);assertSameValue(false,strpos($response['body'],'DB_TOUCHED'));
 }
});
test('admin GET HEAD OPTIONS and PUT cannot execute payroll even with legacy query parameters',function()use($fixture,$admin){
 foreach(['GET','HEAD','OPTIONS','PUT']as$method){$response=payrollBoundaryRequest($fixture,['session'=>$admin,'server'=>['REQUEST_METHOD'=>$method],'get'=>['PeriodID'=>'1','member_id'=>'0']]);assertSameValue(405,$response['status']);assertContains('ERROR:',$response['body']);assertSameValue(false,strpos($response['body'],'DB_TOUCHED'));}
});
test('cross origin and unverifiable mutation fail before database',function()use($fixture,$admin){
 foreach([['server'=>['HTTP_ORIGIN'=>'https://outside.test']],['unset'=>['HTTP_ORIGIN']]]as$request){$request['session']=$admin;$response=payrollBoundaryRequest($fixture,$request);assertSameValue(403,$response['status']);assertSameValue(false,strpos($response['body'],'DB_TOUCHED'));}
});
test('missing array malformed and oversized selectors fail before database',function()use($fixture,$admin){
 foreach([[],['PeriodID'=>'1'],['PeriodID'=>['1'],'member_id'=>'0'],['PeriodID'=>'1junk','member_id'=>'0'],['PeriodID'=>'2147483648','member_id'=>'0'],['PeriodID'=>'0','member_id'=>'0'],['PeriodID'=>'1','member_id'=>[]],['PeriodID'=>'1','member_id'=>"bad\nmember"],['PeriodID'=>'1','member_id'=>str_repeat('m',256)]]as$post){$response=payrollBoundaryRequest($fixture,['session'=>$admin,'post'=>$post]);assertSameValue(400,$response['status']);assertSameValue(false,strpos($response['body'],'DB_TOUCHED'));}
});
test('same origin admin POST accepts explicit all selectors and alphanumeric member',function()use($fixture,$admin){
 foreach(['0','-1','COOP-001','00']as$member){$response=payrollBoundaryRequest($fixture,['session'=>$admin,'post'=>['PeriodID'=>'1','member_id'=>$member]]);assertSameValue(200,$response['status']);assertSameValue('DB_TOUCHED',$response['body']);}
});
test('same origin Fetch Metadata supports verified POST without Origin header',function()use($fixture,$admin){
 $response=payrollBoundaryRequest($fixture,['session'=>$admin,'unset'=>['HTTP_ORIGIN'],'server'=>['HTTP_SEC_FETCH_SITE'=>'same-origin']]);assertSameValue('DB_TOUCHED',$response['body']);
});
test('payroll page rejects unauthorized access before shared header',function()use($fixture){
 foreach([[],['SESS_MEMBER_ID'=>'7','role'=>'Member']]as$session){$response=payrollBoundaryRequest($fixture,['page'=>true,'session'=>$session]);assertSameValue(403,$response['status']);assertSameValue(false,strpos($response['body'],'HEADER_TOUCHED'));}
});
test('authorized payroll page reaches shared header',function()use($fixture,$admin){$response=payrollBoundaryRequest($fixture,['page'=>true,'session'=>$admin]);assertSameValue('HEADER_TOUCHED',$response['body']);});
foreach(glob($fixture.'/sessions/*')as$file)unlink($file);
foreach(['/classes/process.php','/payprocess.php','/includes/admin_access_helper.php','/includes/session_helper.php','/includes/header.php','/Connections/coop.php']as$file)unlink($fixture.$file);
foreach(['/classes','/includes','/Connections','/sessions','']as$folder)rmdir($fixture.$folder);
finishTests();
