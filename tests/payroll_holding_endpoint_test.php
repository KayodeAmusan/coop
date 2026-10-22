<?php
/** Actual endpoint source + real PDO, with synthetic connection wiring in an isolated temporary fixture. */
require_once __DIR__ . '/helpers.php';
if (getenv('COOP_HOLDING_SETUP_TEST') !== '1') { fwrite(STDERR,"Set COOP_HOLDING_SETUP_TEST=1 for isolated loopback MariaDB tests.\n"); exit(2); }
if (($argv[1] ?? '') === 'worker') {
    $fixture=$argv[2];$request=json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR);
    $_SERVER=array_merge(['REQUEST_METHOD'=>'POST','HTTP_HOST'=>'coop.test','HTTP_ORIGIN'=>'http://coop.test','PHP_SELF'=>'/payroll_holding_setup.php'],$request['server']??[]);
    foreach($request['unset']??[] as $key)unset($_SERVER[$key]);
    $_POST=$request['post']??[];
    session_save_path($fixture.'/sessions');session_id('holdingtest'.bin2hex(random_bytes(8)));session_start();$_SESSION=$request['session']??[];
    http_response_code(200);ob_start();
    register_shutdown_function(function(){ $body=ob_get_clean();echo json_encode(['status'=>http_response_code(),'body'=>$body],JSON_THROW_ON_ERROR); });
    require $fixture . (($request['page']??false)?'/payroll_holding_setup.php':'/api/configure_payroll_holding.php');exit;
}
$dsn='mysql:host=127.0.0.1;port=13307;dbname=coop_test_holding_setup;charset=utf8mb4';
$server=new PDO('mysql:host=127.0.0.1;port=13307;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS coop_test_holding_setup');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['coop_payroll_unallocated','coop_payroll_configuration','coop_audit_trail','coop_journal_entries','coop_accounts']as$table)$db->exec('DROP TABLE IF EXISTS '.$table);
$schema=file_get_contents(__DIR__.'/../SETUP_FULL_ACCOUNTING_SYSTEM.sql');
foreach(['coop_accounts','coop_journal_entries','coop_audit_trail']as$table){
 if(!preg_match('/CREATE TABLE IF NOT EXISTS '.$table.'\s*\(.*?ENGINE=InnoDB[^;]*;/s',$schema,$sql))throw new RuntimeException('Missing test schema');$db->exec($sql[0]);
}
$db->exec(file_get_contents(__DIR__.'/../database/migrations/20261021_payroll_holding.sql'));
$stmt=$db->prepare('INSERT INTO coop_accounts(id,account_code,account_name,account_type,normal_balance,is_active,is_control_account)VALUES(?,?,?,?,?,?,?)');
foreach([[101,'1001','Synthetic existing account','asset','debit',1,0],[102,'1002','<img src=x onerror=bad()>','liability','credit',1,0],[103,'1003','Inactive account','asset','debit',0,0],[104,'1004','Control account','asset','debit',1,1]]as$row)$stmt->execute($row);
$fixture=sys_get_temp_dir().'/coop_holding_setup_'.bin2hex(random_bytes(6));
foreach(['','/api','/includes','/Connections','/sessions']as$folder)mkdir($fixture.$folder,0700);
$repo=dirname(__DIR__);
copy($repo.'/api/configure_payroll_holding.php',$fixture.'/api/configure_payroll_holding.php');copy($repo.'/payroll_holding_setup.php',$fixture.'/payroll_holding_setup.php');
foreach(['admin_access_helper.php','session_helper.php','payroll_holding_helper.php','header.php','footer.php']as$file){
 file_put_contents($fixture.'/includes/'.$file,'<?php require_once '.var_export($repo.'/includes/'.$file,true).';');
}
file_put_contents($fixture.'/Connections/coop.php','<?php file_put_contents('.var_export($fixture.'/connection-access',true).',"1",FILE_APPEND); $coop=new PDO('.var_export($dsn,true).',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $conn=$coop;$database="coop_test_holding_setup";');
function holdingRequest($fixture,array$request){
 $process=proc_open([PHP_BINARY,__FILE__,'worker',$fixture,base64_encode(json_encode($request,JSON_THROW_ON_ERROR))],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Cannot run endpoint fixture');
 $output=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
 if($code!==0)throw new RuntimeException('Endpoint fixture failed: '.$stderr.$output);
 $response=json_decode($output,true,512,JSON_THROW_ON_ERROR);return$response;
}
function holdingAdmin($extra=[]){return array_merge(['session'=>['SESS_MEMBER_ID'=>'7','role'=>'Admin'],'post'=>['holding_account_id'=>'101']],$extra);}
function holdingConnectionTouches($fixture){clearstatcache(true,$fixture.'/connection-access');return file_exists($fixture.'/connection-access')?filesize($fixture.'/connection-access'):0;}
function holdingJson($response){return json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);}

test('endpoint access method origin and exact IDs are rejected before database access',function()use($fixture){
 $before=holdingConnectionTouches($fixture);
 foreach([
  [[],403],
  [['session'=>['SESS_MEMBER_ID'=>'7','role'=>'Member']],403],
  [holdingAdmin(['server'=>['REQUEST_METHOD'=>'GET']]),405],
  [holdingAdmin(['server'=>['HTTP_ORIGIN'=>'https://outside.test']]),403],
  [holdingAdmin(['unset'=>['HTTP_ORIGIN']]),403],
  [holdingAdmin(['post'=>['holding_account_id'=>['101']]]),400],
  [holdingAdmin(['post'=>['holding_account_id'=>'101junk']]),400],
  [holdingAdmin(['post'=>['holding_account_id'=>'2147483648']]),400],
  [holdingAdmin(['session'=>['SESS_MEMBER_ID'=>'7junk','role'=>'Admin']]),400]
 ]as[$request,$status]){assertSameValue($status,holdingRequest($fixture,$request)['status']);}
 assertSameValue($before,holdingConnectionTouches($fixture));
});
test('setup page rejects non-admins before database or private markup',function()use($fixture){
 $before=holdingConnectionTouches($fixture);$response=holdingRequest($fixture,['page'=>true,'session'=>['SESS_MEMBER_ID'=>'7','role'=>'Member']]);
 assertSameValue(403,$response['status']);assertSameValue($before,holdingConnectionTouches($fixture));assertSameValue(false,strpos($response['body'],'Synthetic existing account')!==false);
});
test('initial setup renders a labelled required empty choice without account default',function()use($fixture){
 $response=holdingRequest($fixture,holdingAdmin(['page'=>true]));assertSameValue(200,$response['status']);
 $dom=new DOMDocument();@$dom->loadHTML($response['body']);$xpath=new DOMXPath($dom);
 assertSameValue(1,$xpath->query('//label[@for="holding-account"]')->length);
 assertSameValue(1,$xpath->query('//select[@name="holding_account_id"][@required]')->length);
 assertSameValue(1,$xpath->query('//select[@id="holding-account"]/option[@selected][@value=""]')->length);
 assertSameValue(0,$xpath->query('//select[@id="holding-account"]/option[@selected][@value!=""]')->length);
 assertSameValue(0,$xpath->query('//select[@id="holding-account"]/option[@value="103" or @value="104"]')->length);
 assertContains('&lt;img src=x onerror=bad()&gt;',$response['body']);
});
test('save selects only an existing account and records authenticated administrator audit',function()use($fixture,$db){
 $response=holdingRequest($fixture,holdingAdmin());assertSameValue(200,$response['status']);assertSameValue(true,holdingJson($response)['success']);
 assertSameValue('101',(string)$db->query('SELECT holding_account_id FROM coop_payroll_configuration WHERE id=1')->fetchColumn());
 assertSameValue('7',(string)$db->query("SELECT user_id FROM coop_audit_trail WHERE table_name='coop_payroll_configuration' ORDER BY id DESC LIMIT 1")->fetchColumn());
 assertSameValue(4,(int)$db->query('SELECT COUNT(*) FROM coop_accounts')->fetchColumn());
});
test('edit explicitly selects existing configured account and safely escapes account text',function()use($fixture,$db){
 $response=holdingRequest($fixture,holdingAdmin(['post'=>['holding_account_id'=>'102','configured_by'=>'999']]));assertSameValue(200,$response['status']);
 assertSameValue('7',(string)$db->query('SELECT configured_by FROM coop_payroll_configuration')->fetchColumn());
 $page=holdingRequest($fixture,holdingAdmin(['page'=>true]));$dom=new DOMDocument();@$dom->loadHTML($page['body']);$xpath=new DOMXPath($dom);
 assertSameValue(1,$xpath->query('//select[@id="holding-account"]/option[@value="102"][@selected]')->length);
 assertSameValue(0,$xpath->query('//select[@id="holding-account"]/option[@value="101"][@selected]')->length);
 assertSameValue(0,$xpath->query('//section[@class="payroll-setup"]//img')->length);
});
test('inactive control and nonexistent account selections cannot change configuration',function()use($fixture,$db){
 foreach(['103','104','999']as$id){$response=holdingRequest($fixture,holdingAdmin(['post'=>['holding_account_id'=>$id]]));assertSameValue(400,$response['status']);assertSameValue(false,holdingJson($response)['success']);}
 assertSameValue('102',(string)$db->query('SELECT holding_account_id FROM coop_payroll_configuration')->fetchColumn());
});
test('audit failure rolls configuration update back and reports uncertain failure truthfully',function()use($fixture,$db){
 $db->exec("CREATE TRIGGER holding_setup_audit_failure BEFORE INSERT ON coop_audit_trail FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic setup audit failure'");
 try{$response=holdingRequest($fixture,holdingAdmin(['post'=>['holding_account_id'=>'101']]));assertSameValue(500,$response['status']);assertSameValue(false,holdingJson($response)['success']);assertContains('could not be confirmed',holdingJson($response)['error']);}
 finally{$db->exec('DROP TRIGGER holding_setup_audit_failure');}
 assertSameValue('102',(string)$db->query('SELECT holding_account_id FROM coop_payroll_configuration')->fetchColumn());
});
test('unavailable prior configuration warns and returns to an explicit empty choice',function()use($fixture,$db){
 $db->exec('UPDATE coop_accounts SET is_active=0 WHERE id=102');
 $response=holdingRequest($fixture,holdingAdmin(['page'=>true]));assertSameValue(200,$response['status']);assertContains('configured account is unavailable',$response['body']);
 $dom=new DOMDocument();@$dom->loadHTML($response['body']);$xpath=new DOMXPath($dom);assertSameValue(1,$xpath->query('//select/option[@selected][@value=""]')->length);
});
$resolvedFixture=realpath($fixture);$resolvedTemp=realpath(sys_get_temp_dir());
if(!$resolvedFixture||dirname($resolvedFixture)!==$resolvedTemp||strpos(basename($resolvedFixture),'coop_holding_setup_')!==0)throw new RuntimeException('Refusing fixture cleanup outside temporary directory');
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedFixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($iterator as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($resolvedFixture);
finishTests();
