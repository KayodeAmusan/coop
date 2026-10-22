<?php
require_once __DIR__.'/helpers.php';
$fixture=sys_get_temp_dir().'/coop_member_scope_'.bin2hex(random_bytes(6));
foreach (['auth_api/admin','auth_api/api/profile','auth_api/api/payroll','auth_api/api/transactions','auth_api/config','auth_api/utils','includes','Connections'] as $dir) mkdir($fixture.'/'.$dir,0700,true);
copy(__DIR__.'/../auth_api/admin/handle_approval.php',$fixture.'/auth_api/admin/handle_approval.php');
file_put_contents($fixture.'/auth_api/utils/EmailService.php','<?php class EmailService { public function __construct($db){throw new RuntimeException("Mail must not initialize before authorization");} }');
file_put_contents($fixture.'/admin_review.php','<?php session_start(); $_SESSION=["admin_id"=>($_GET["reviewer"]??"1"),"staff_id"=>"forged"]; session_write_close(); chdir(__DIR__."/auth_api/admin"); require "handle_approval.php";');
foreach (['pending_changes.php','submit_changes.php','get_salary_info.php'] as $route) copy(__DIR__.'/../auth_api/api/profile/'.$route,$fixture.'/auth_api/api/profile/'.$route);
copy(__DIR__.'/../auth_api/api/payroll/payslip.php',$fixture.'/auth_api/api/payroll/payslip.php');
copy(__DIR__.'/../auth_api/api/transactions/transaction-summary.php',$fixture.'/auth_api/api/transactions/transaction-summary.php');
copy(__DIR__.'/../coop_member_statement.php',$fixture.'/coop_member_statement.php');
foreach (['member_identity_helper.php','validation_helper.php','loan_consent_helper.php','money_helper.php','session_helper.php','admin_access_helper.php'] as $file) copy(__DIR__.'/../includes/'.$file,$fixture.'/includes/'.$file);
copy(__DIR__.'/../auth_api/utils/JWTHandler.php',$fixture.'/auth_api/utils/JWTHandler.php');
file_put_contents($fixture.'/auth_api/config/config.php','<?php return ["jwt"=>["secret"=>str_repeat("synthetic-key",4),"expiry"=>3600]];');
file_put_contents($fixture.'/auth_api/config/Database.php','<?php class Database { public function getConnection(){return new PDO("sqlite:".__DIR__."/fixture.sqlite",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);} }');
file_put_contents($fixture.'/Connections/coop.php','<?php exit("UNAUTHORIZED DATABASE ACCESS");');
$db=new PDO('sqlite:'.$fixture.'/auth_api/config/fixture.sqlite');
$db->exec("CREATE TABLE tblemployees (CoopID TEXT, StaffID INTEGER, Status TEXT); INSERT INTO tblemployees VALUES ('COOP-A',7,'Active'),('COOP-B',8,'Active'); CREATE TABLE employee(staff_id INTEGER,has_pending_changes INTEGER); INSERT INTO employee VALUES(7,1),(8,1); CREATE TABLE pending_profile_changes(staff_id INTEGER,field_name TEXT,old_value TEXT,new_value TEXT,submitted_at TEXT,status TEXT); INSERT INTO pending_profile_changes VALUES(7,'NAME','Old','Own pending','2026-10-22','pending'),(8,'NAME','Other','Private other','2026-10-22','pending'); CREATE TABLE pending_qualification_changes(id INTEGER,staff_id INTEGER,qua_id INTEGER,field TEXT,institution TEXT,year_obtained TEXT,change_type TEXT,original_qualification_id INTEGER,submitted_at TEXT,status TEXT);");
$db->exec('ALTER TABLE employee ADD COLUMN is_admin INTEGER; UPDATE employee SET is_admin=1 WHERE staff_id=7; CREATE TABLE tbl_users(id INTEGER,staff_id INTEGER); INSERT INTO tbl_users VALUES(1,7),(2,8)');
require $fixture.'/auth_api/utils/JWTHandler.php';
$token=(new JWTHandler())->generateToken('COOP-A');
$socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
$process=proc_open([PHP_BINARY,'-d','display_errors=0','-S','127.0.0.1:'.$port,'-t',$fixture],[0=>['pipe','r'],1=>['file',$fixture.'/http.log','a'],2=>['file',$fixture.'/http.log','a']],$pipes);fclose($pipes[0]);
for($i=0;$i<100;$i++){if($ready=@fsockopen('127.0.0.1',$port)){fclose($ready);break;}usleep(20000);}
$request=function($route,$body=null) use($port,$token){
 $result=file_get_contents('http://127.0.0.1:'.$port.'/'.$route,false,stream_context_create(['http'=>['method'=>$body===null?'GET':'POST','header'=>"Authorization: Bearer $token\r\nContent-Type: application/json\r\nOrigin: http://127.0.0.1:$port",'content'=>$body===null?'':json_encode($body),'ignore_errors'=>true,'timeout'=>5]]));
 return [$http_response_header[0],$result];
};
try {
 test('real pending-profile route returns only authenticated mapped payroll identity',function()use($request){[$status,$body]=$request('auth_api/api/profile/pending_changes.php?staff_id=7');assertContains('200',$status);$data=json_decode($body,true,512,JSON_THROW_ON_ERROR);assertSameValue('Own pending',$data['data']['pending_profile_changes']['NAME']);assertSameValue(false,str_contains($body,'Private other'));});
 foreach (['profile/pending_changes.php?staff_id=8','profile/get_salary_info.php?staff_id=8','payroll/payslip.php?userId=8&periodId=1'] as $route) test($route.' denies another member before data query',function()use($route,$request){[$status,$body]=$request('auth_api/api/'.$route);assertContains('403',$status);assertSameValue(false,json_decode($body,true,512,JSON_THROW_ON_ERROR)['success']);});
 test('profile mutation rejects cross member and injected fields',function()use($request){foreach([[8,['NAME'=>'Other'],403],[7,['is_admin'=>1],400],[7,['NAME = NULL, is_admin'=>1],400]]as[$id,$changes,$expected]){[$status,$body]=$request('auth_api/api/profile/submit_changes.php',['staff_id'=>$id,'profile_changes'=>$changes]);assertContains((string)$expected,$status);assertSameValue(false,json_decode($body,true,512,JSON_THROW_ON_ERROR)['success']);}});
 test('transaction summary denies caller-selected CoopID',function()use($request){[$status,$body]=$request('auth_api/api/transactions/transaction-summary.php',['coopId'=>'COOP-B','fromPeriod'=>1,'toPeriod'=>2]);assertContains('403',$status);assertSameValue(false,json_decode($body,true,512,JSON_THROW_ON_ERROR)['success']);});
 test('staff statement fails closed before connection for anonymous visitor',function()use($request){[$status,$body]=$request('coop_member_statement.php?memberid=8');assertContains('403',$status);assertSameValue(false,str_contains($body,'UNAUTHORIZED DATABASE ACCESS'));});
 test('profile reviewer is resolved from live authority and cannot approve self',function()use($request){foreach([['1',7],['2',7]]as[$reviewer,$target]){[$status,$body]=$request('admin_review.php?reviewer='.$reviewer,['staff_id'=>$target,'action'=>'approve']);assertContains('403',$status);assertSameValue(false,json_decode($body,true,512,JSON_THROW_ON_ERROR)['success']);assertSameValue(false,str_contains($body,'Mail must not initialize'));}});
} finally {
 proc_terminate($process);proc_close($process);$db=null;
 $root=realpath($fixture);if(!$root||!str_starts_with($root,realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'coop_member_scope_'))throw new RuntimeException('Unsafe fixture cleanup');
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($root);
}
finishTests();
