<?php
require_once __DIR__.'/helpers.php';
$fixture=sys_get_temp_dir().'/coop_otp_boundary_'.bin2hex(random_bytes(6));
foreach(['','/api','/api/auth','/config','/utils'] as $dir) mkdir($fixture.$dir,0700);
foreach(['request_otp.php','send_signup_otp.php'] as $file) copy(__DIR__.'/../auth_api/api/auth/'.$file,$fixture.'/api/auth/'.$file);
foreach(['Validator.php','OtpThrottle.php'] as $file) copy(__DIR__.'/../auth_api/utils/'.$file,$fixture.'/utils/'.$file);
file_put_contents($fixture.'/config/Database.php', <<<'PHP'
<?php
class Database { public function getConnection(){return new OtpFixtureDatabase();} }
class OtpFixtureDatabase {
 public function prepare($sql){return new OtpFixtureStatement();}
 public function beginTransaction(){file_put_contents(__DIR__.'/../transaction','started');}
 public function inTransaction(){return true;}
 public function rollBack(){file_put_contents(__DIR__.'/../transaction','rolled_back');}
 public function commit(){file_put_contents(__DIR__.'/../transaction','committed');}
}
class OtpFixtureStatement {
 public function bindParam($name,&$value){}
 public function execute(){}
 public function rowCount(){return 0;}
 public function fetch($mode){return ['recent_count'=>0,'seconds_since_last'=>null];}
}
PHP);
// A failed transport is deliberate: this test never claims real mail delivery.
file_put_contents($fixture.'/utils/EmailService.php','<?php class EmailService { public function __construct($db){} public function sendOTP($email,$otp){return false;} }');
$socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
$process=proc_open([PHP_BINARY,'-d','display_errors=0','-S','127.0.0.1:'.$port,'-t',$fixture],[0=>['pipe','r'],1=>['file',$fixture.'/http.log','a'],2=>['file',$fixture.'/http.log','a']],$pipes);
if(!is_resource($process)) throw new RuntimeException('Cannot start OTP route fixture');fclose($pipes[0]);
for($i=0;$i<100;$i++){if($ready=@fsockopen('127.0.0.1',$port)){fclose($ready);break;}usleep(20000);}
try {
 foreach(['request_otp.php','send_signup_otp.php'] as $route) test($route.' rolls back and returns failure when transport returns false',function() use($fixture,$port,$route){
  $body=file_get_contents('http://127.0.0.1:'.$port.'/api/auth/'.$route,false,stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/json','content'=>json_encode(['email'=>'synthetic@example.invalid']),'ignore_errors'=>true,'timeout'=>5]]));
  assertContains('400',$http_response_header[0]);
  assertSameValue(false,json_decode($body,true,512,JSON_THROW_ON_ERROR)['success']);
  assertSameValue('rolled_back',file_get_contents($fixture.'/transaction'));
 });
} finally {
 proc_terminate($process);proc_close($process);
 $root=realpath($fixture);$temporary=realpath(sys_get_temp_dir());
 if(!$root||!str_starts_with($root,$temporary.DIRECTORY_SEPARATOR.'coop_otp_boundary_'))throw new RuntimeException('Unsafe fixture cleanup');
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($root);
}
finishTests();
