<?php
/** Real multipart HTTP + actual endpoint/service/PDO against disposable local fixtures only. */
require_once __DIR__.'/helpers.php';
if(getenv('COOP_EVIDENCE_MYSQL_TEST')!=='1'){fwrite(STDERR,"Set COOP_EVIDENCE_MYSQL_TEST=1 for fixed isolated loopback tests.\n");exit(2);}
$server=new PDO('mysql:host=127.0.0.1;port=13307;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$server->exec('CREATE DATABASE IF NOT EXISTS coop_test_evidence');
$db=new PDO('mysql:host=127.0.0.1;port=13307;dbname=coop_test_evidence;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['loan_guarantor_evidence_history','loan_guarantor_evidence','guarantor_requests','loan_requests','loan_period_limits','tblemployees']as$table)$db->exec("DROP TABLE IF EXISTS $table");
$db->exec('CREATE TABLE tblemployees(CoopID VARCHAR(255) PRIMARY KEY,Status VARCHAR(20)) ENGINE=InnoDB');
$db->exec("INSERT INTO tblemployees VALUES('borrower','Active'),('g1','Active'),('g2','Active'),('other','Active')");
$db->exec('CREATE TABLE loan_requests(id INT PRIMARY KEY,requester_coop_id VARCHAR(255),requested_amount DECIMAL(15,2),monthly_repayment DECIMAL(15,2),status VARCHAR(30),period_id INT,submitted_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB');
$db->exec("INSERT INTO loan_requests VALUES(1,'borrower',100.00,10.00,'pending_guarantors',1,NULL,NULL)");
$db->exec('CREATE TABLE guarantor_requests(id INT PRIMARY KEY,loan_request_id INT,guarantor_coop_id VARCHAR(255),status VARCHAR(20),requested_amount DECIMAL(15,2),monthly_repayment DECIMAL(15,2),responded_by_coop_id VARCHAR(255) NULL,consent_terms_hash CHAR(64) NULL) ENGINE=InnoDB');
$db->exec("INSERT INTO guarantor_requests VALUES(1,1,'g1','pending',100.00,10.00,NULL,NULL),(2,1,'g2','pending',100.00,10.00,NULL,NULL)");
$db->exec('CREATE TABLE loan_period_limits(period_id INT PRIMARY KEY,limit_amount DECIMAL(15,2)) ENGINE=InnoDB');
$migration=file_get_contents(__DIR__.'/../database/migrations/20261021_guarantor_evidence.sql');$db->exec($migration);$db->exec($migration);
$fixture=sys_get_temp_dir().'/coop_evidence_'.bin2hex(random_bytes(6));$web=$fixture.'/web';$private=$fixture.'/private';$sessions=$fixture.'/sessions';
foreach(['','/web','/private','/sessions','/web/includes','/web/config','/web/vendor','/web/auth_api','/web/auth_api/config','/web/auth_api/utils','/web/auth_api/api','/web/auth_api/api/loans','/web/auth_api/api/admin']as$folder)mkdir($fixture.$folder,0700);
$repo=dirname(__DIR__);
foreach(['includes/private_document_storage.php','includes/guarantor_evidence_service.php','includes/guarantor_evidence_config.php','includes/guarantor_evidence_endpoint.php','includes/loan_consent_helper.php','includes/money_helper.php','includes/admin_access_helper.php','includes/session_helper.php','config/EnvConfig.php','auth_api/config/Database.php','auth_api/config/config.php','auth_api/utils/JWTHandler.php','auth_api/api/loans/guarantor-evidence.php','auth_api/api/admin/guarantor-evidence.php']as$file)copy($repo.'/'.$file,$web.'/'.$file);
$databaseSource=file_get_contents($web.'/auth_api/config/Database.php');
$faultFlag=$fixture.'/lose-commit-ack';
$faultClass='class EvidenceCommitFaultPDO extends PDO { public function commit(): bool { $result=parent::commit(); if(file_exists('.var_export($faultFlag,true).')) { unlink('.var_export($faultFlag,true).'); throw new RuntimeException("Synthetic lost commit acknowledgement"); } return $result; } }';
$databaseSource=preg_replace('/<\?php/','<?php '.$faultClass,$databaseSource,1);$databaseSource=str_replace('new PDO(', 'new EvidenceCommitFaultPDO(', $databaseSource);file_put_contents($web.'/auth_api/config/Database.php',$databaseSource);
if(!file_exists($repo.'/vendor/autoload.php'))throw new RuntimeException('Install actual Composer dependencies before evidence HTTP tests');
file_put_contents($web.'/vendor/autoload.php','<?php require_once '.var_export($repo.'/vendor/autoload.php',true).';');
$secret=str_repeat('synthetic-evidence-key-',3);
$baseEnv="DB_HOST=\"127.0.0.1;port=13307\"\nDB_NAME=coop_test_evidence\nDB_USER=root\nDB_USERNAME=root\nDB_PASSWORD=\"\"\nJWT_SECRET=$secret\nPRIVATE_DOCUMENT_STORAGE_ROOT=\"".str_replace('\\','/',$private)."\"\n";
file_put_contents($web.'/.env',$baseEnv);
file_put_contents($fixture.'/scanner.php','<?php return function($path){return ["status"=>strpos(file_get_contents($path),"SYNTHETIC-MALWARE")!==false?"infected":"clean","engine"=>"Synthetic scanner fixture only"];};');
file_put_contents($web.'/router.php','<?php if($_SERVER["REQUEST_URI"]==="/health"){echo "ready";return;} if(str_starts_with($_SERVER["REQUEST_URI"],"/test-session?")){session_start();$_SESSION=["SESS_MEMBER_ID"=>$_GET["id"]??"7","role"=>$_GET["role"]??"Admin"];echo "session";return;} return false;');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);$base='http://127.0.0.1:'.$port;
$environment=getenv();$environment['JWT_SECRET']=$secret;
$log=fopen($fixture.'/server.log','a');
$process=proc_open([PHP_BINARY,'-d','session.save_path='.$sessions,'-d','display_errors=0','-d','upload_max_filesize=6M','-d','post_max_size=7M','-S','127.0.0.1:'.$port,'-t',$web,$web.'/router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes,$web,$environment);
if(!is_resource($process))throw new RuntimeException('Cannot start local evidence HTTP fixture');fclose($pipes[0]);
for($attempt=0;$attempt<100;$attempt++){if(@file_get_contents($base.'/health')==='ready')break;usleep(20000);}
function evidenceHttp($path,$method='GET',$body='',array$headers=[]){global$base;$context=stream_context_create(['http'=>['method'=>$method,'content'=>$body,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>10]]);$content=file_get_contents($base.$path,false,$context);$received=$http_response_header??[];preg_match('/\s(\d{3})\s/',$received[0]??'',$status);return['status'=>(int)($status[1]??0),'body'=>$content,'headers'=>$received];}
function evidenceToken($id){global$secret;$encode=fn($v)=>rtrim(strtr(base64_encode(json_encode($v)),'+/','-_'),'=');$body=$encode(['alg'=>'HS256','typ'=>'JWT']).'.'.$encode(['user_id'=>$id,'iat'=>time(),'exp'=>time()+3600]);return$body.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256',$body,$secret,true)),'+/','-_'),'=');}
function evidenceMember($id){return['Authorization: Bearer '.evidenceToken($id)];}
function evidenceAdmin($id='7'){global$base;$r=evidenceHttp('/test-session?id='.$id);foreach($r['headers']as$header)if(stripos($header,'Set-Cookie:')===0){$cookie=explode(';',trim(substr($header,11)))[0];return['Cookie: '.$cookie,'Origin: '.$base];}throw new RuntimeException('No test session cookie');}
function evidenceUpload($id,$key,$bytes,array$headers,$kind='approval',$filename='signed.pdf',$mime='application/pdf',$wireOnly=false){
 $boundary='evidence'.bin2hex(random_bytes(8));$body='';foreach(['guarantor_request_id'=>$id,'evidence_kind'=>$kind,'upload_key'=>$key]as$name=>$value)$body.="--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
 $body.="--$boundary\r\nContent-Disposition: form-data; name=\"evidence\"; filename=\"$filename\"\r\nContent-Type: $mime\r\n\r\n$bytes\r\n--$boundary--\r\n";
 $staff=false;foreach($headers as$header)if(str_starts_with($header,'Cookie:'))$staff=true;
 $wire=['path'=>'/auth_api/api/'.($staff?'admin':'loans').'/guarantor-evidence.php','method'=>'POST','body'=>$body,'headers'=>array_merge($headers,['Content-Type: multipart/form-data; boundary='.$boundary])];if($wireOnly)return$wire;
 return evidenceHttp($wire['path'],$wire['method'],$wire['body'],$wire['headers']);
}
function evidenceData($response){$data=json_decode($response['body'],true);if(!is_array($data))throw new RuntimeException('Non-JSON response: '.substr($response['body'],0,300));return$data;}
function evidenceReview($document,$decision,$notes,$headers){return evidenceHttp('/auth_api/api/admin/guarantor-evidence.php','POST',json_encode(['action'=>'review','document_id'=>$document,'decision'=>$decision,'notes'=>$notes]),array_merge($headers,['Content-Type: application/json']));}
$pdf="%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\ntrailer <</Root 1 0 R>>\n%%EOF\n";$admin=evidenceAdmin();$otherAdmin=evidenceAdmin('8');
try {
test('authenticated association discovery protects member boundaries',function(){
 assertSameValue(401,evidenceHttp('/auth_api/api/loans/guarantor-evidence.php?guarantor_request_id=1')['status']);
 $r=evidenceHttp('/auth_api/api/loans/guarantor-evidence.php?action=requests&loan_request_id=1','GET','',evidenceMember('borrower'));assertSameValue(200,$r['status']);assertSameValue(2,count(evidenceData($r)['data']['requests']));
 $r=evidenceHttp('/auth_api/api/loans/guarantor-evidence.php?action=requests&loan_request_id=1','GET','',evidenceMember('g1'));assertSameValue(1,count(evidenceData($r)['data']['requests']));
 assertSameValue(403,evidenceHttp('/auth_api/api/loans/guarantor-evidence.php?guarantor_request_id=1','GET','',evidenceMember('other'))['status']);
});
test('real multipart without scanner stays private and quarantined',function()use($pdf,&$quarantined){
 $r=evidenceUpload(1,'quarantine-key-0001',$pdf,evidenceMember('borrower'));if($r['status']!==200)throw new RuntimeException('Upload failed: '.$r['body']);$quarantined=evidenceData($r)['data'];assertSameValue('quarantined',$quarantined['scan_status']);assertSameValue('borrower',$quarantined['uploaded_by_id']);assertSameValue(false,isset($quarantined['storage_key']));assertSameValue(false,isset($quarantined['file_path']));
});
test('quarantined evidence blocks review and download',function()use(&$quarantined,$admin){
 assertSameValue(409,evidenceReview($quarantined['public_id'],'accepted','Reviewed',$admin)['status']);assertSameValue(409,evidenceHttp('/auth_api/api/loans/guarantor-evidence.php?action=download&document_id='.$quarantined['public_id'],'GET','',evidenceMember('borrower'))['status']);
});
test('explicit external scanner fixture permits rescan and staff review',function()use(&$quarantined,$web,$baseEnv,$fixture,$admin,&$accepted){
 file_put_contents($web.'/.env',$baseEnv.'DOCUMENT_SCANNER_ADAPTER="'.str_replace('\\','/',$fixture.'/scanner.php').'"'."\n");
 $r=evidenceHttp('/auth_api/api/admin/guarantor-evidence.php','POST',json_encode(['action'=>'rescan','document_id'=>$quarantined['public_id']]),array_merge($admin,['Content-Type: application/json']));assertSameValue(200,$r['status']);assertSameValue('clean',evidenceData($r)['data']['scan_status']);
 $r=evidenceReview($quarantined['public_id'],'accepted','Signed approval inspected',$admin);assertSameValue(200,$r['status']);$accepted=evidenceData($r)['data'];assertSameValue('accepted',$accepted['review_status']);assertSameValue('staff',$accepted['reviewed_by_type']);
});
test('upload retries preserve accepted version and reject changed content',function()use($pdf,$db,&$accepted){
 $before=(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn();$r=evidenceUpload(1,'quarantine-key-0001',$pdf,evidenceMember('borrower'));assertSameValue(200,$r['status']);assertSameValue($accepted['public_id'],evidenceData($r)['data']['public_id']);assertSameValue('accepted',evidenceData($r)['data']['review_status']);assertSameValue($before,(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn());
 assertSameValue(409,evidenceUpload(1,'quarantine-key-0001',str_replace('1.4','1.5',$pdf),evidenceMember('borrower'))['status']);
});
test('guarantor PNG and staff review enable offline submission without impersonation',function()use($admin,$db,&$second){
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=');
 $r=evidenceUpload(2,'guarantor-key-0002',$png,evidenceMember('g2'),'approval','signed.png','image/png');assertSameValue(200,$r['status']);$second=evidenceData($r)['data'];assertSameValue('image/png',$second['mime_type']);assertSameValue(200,evidenceReview($second['public_id'],'accepted','Signed guarantor evidence inspected',$admin)['status']);assertSameValue('submitted',$db->query('SELECT status FROM loan_requests WHERE id=1')->fetchColumn());assertSameValue(0,(int)$db->query('SELECT COUNT(*) FROM guarantor_requests WHERE responded_by_coop_id IS NOT NULL')->fetchColumn());
});
test('staff uploader requires a different reviewer',function()use($pdf,$admin,$otherAdmin,$db,&$staffDocument){
 $r=evidenceUpload(1,'staff-upload-key-003',$pdf,$admin);assertSameValue(200,$r['status']);$staffDocument=evidenceData($r)['data'];assertSameValue('staff',$staffDocument['uploaded_by_type']);assertSameValue('partially_guaranteed',$db->query('SELECT status FROM loan_requests WHERE id=1')->fetchColumn());assertSameValue(409,evidenceReview($staffDocument['public_id'],'accepted','Self review',$admin)['status']);assertSameValue(200,evidenceReview($staffDocument['public_id'],'accepted','Independent inspection',$otherAdmin)['status']);
});
test('identical review retry is a no-op and decisions cannot be overwritten',function()use(&$staffDocument,$otherAdmin,$db){
 $before=(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence_history')->fetchColumn();assertSameValue(200,evidenceReview($staffDocument['public_id'],'accepted','Independent inspection',$otherAdmin)['status']);assertSameValue($before,(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence_history')->fetchColumn());assertSameValue(409,evidenceReview($staffDocument['public_id'],'rejected','Changed decision',$otherAdmin)['status']);
});
test('private clean download returns exact bytes and safe attachment headers',function()use($pdf,&$staffDocument){
 $r=evidenceHttp('/auth_api/api/loans/guarantor-evidence.php?action=download&document_id='.$staffDocument['public_id'],'GET','',evidenceMember('g1'));assertSameValue(200,$r['status']);assertSameValue($pdf,$r['body']);assertContains('attachment;',implode('\n',$r['headers']));assertContains('nosniff',implode('\n',$r['headers']));assertContains('no-store',implode('\n',$r['headers']));assertSameValue(403,evidenceHttp('/auth_api/api/loans/guarantor-evidence.php?action=download&document_id='.$staffDocument['public_id'],'GET','',evidenceMember('other'))['status']);
});
test('new version invalidates prior acceptance and bounded pages retain latest flags',function()use($pdf,$db,&$newDocument){
 $r=evidenceUpload(1,'explicit-new-key-004',$pdf,evidenceMember('borrower'));assertSameValue(200,$r['status']);$newDocument=evidenceData($r)['data'];assertSameValue('pending',$newDocument['review_status']);assertSameValue('partially_guaranteed',$db->query('SELECT status FROM loan_requests WHERE id=1')->fetchColumn());
 $r=evidenceHttp('/auth_api/api/loans/guarantor-evidence.php?guarantor_request_id=1&limit=1','GET','',evidenceMember('borrower'));$page=evidenceData($r)['data'];assertSameValue(1,count($page['items']));assertSameValue(true,$page['items'][0]['is_latest']);assertSameValue(true,is_string($page['next_cursor']));
 $r=evidenceHttp('/auth_api/api/loans/guarantor-evidence.php?guarantor_request_id=1&limit=1&before_id='.$page['next_cursor'],'GET','',evidenceMember('borrower'));assertSameValue(false,evidenceData($r)['data']['items'][0]['is_latest']);
});
test('changed terms and rejected invitations block amendments',function()use(&$newDocument,$pdf,$db,$admin){
 $db->exec('UPDATE loan_requests SET requested_amount=101.00 WHERE id=1');assertSameValue(409,evidenceReview($newDocument['public_id'],'accepted','Old terms',$admin)['status']);$db->exec('UPDATE loan_requests SET requested_amount=100.00 WHERE id=1');$db->exec("UPDATE guarantor_requests SET status='rejected' WHERE id=1");assertSameValue(409,evidenceUpload(1,'rejected-invite-005',$pdf,evidenceMember('borrower'))['status']);assertSameValue(409,evidenceReview($newDocument['public_id'],'accepted','Rejected invitation',$admin)['status']);$db->exec("UPDATE guarantor_requests SET status='pending' WHERE id=1");
});
test('actual type size and association reject forged uploads',function()use($pdf){
 assertSameValue(400,evidenceUpload(1,'wrong-content-key-6','not a PDF',evidenceMember('borrower'))['status']);assertSameValue(400,evidenceUpload(1,'empty-content-key6','',evidenceMember('borrower'))['status']);assertSameValue(400,evidenceUpload(1,'oversize-file-key6',str_repeat('x',5242881),evidenceMember('borrower'))['status']);assertSameValue(403,evidenceUpload(1,'wrong-guarantor-06',$pdf,evidenceMember('g2'))['status']);
});
test('infected scan result cannot pass staff review',function()use($pdf,$admin,&$infected){
 $r=evidenceUpload(1,'infected-test-key7',str_replace('trailer',"SYNTHETIC-MALWARE\ntrailer",$pdf),evidenceMember('borrower'));assertSameValue(200,$r['status']);$infected=evidenceData($r)['data'];assertSameValue('infected',$infected['scan_status']);assertSameValue(409,evidenceReview($infected['public_id'],'accepted','Cannot accept',$admin)['status']);
});
test('failed evidence history insertion rolls back metadata and private file',function()use($pdf,$db,$private){
 $before=count(glob($private.'/*'));$records=(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn();$db->exec("CREATE TRIGGER evidence_audit_fail BEFORE INSERT ON loan_guarantor_evidence_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic evidence audit failure'");
 try{assertSameValue(500,evidenceUpload(1,'failed-audit-key8',$pdf,evidenceMember('borrower'))['status']);}finally{$db->exec('DROP TRIGGER evidence_audit_fail');}assertSameValue($before,count(glob($private.'/*')));assertSameValue($records,(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn());
});
test('approved loan blocks new uploads and review changes',function()use($pdf,&$newDocument,$admin,$db){
 $db->exec("UPDATE loan_requests SET status='approved' WHERE id=1");assertSameValue(409,evidenceUpload(1,'approved-loan-key9',$pdf,evidenceMember('borrower'))['status']);assertSameValue(409,evidenceReview($newDocument['public_id'],'accepted','Finalized loan',$admin)['status']);$db->exec("UPDATE loan_requests SET status='pending_guarantors' WHERE id=1");
});
test('migration replay preserves immutable versions and provenance',function()use($db,$migration){
 $before=(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn();$db->exec($migration);$db->exec($migration);assertSameValue($before,(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn());assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM loan_guarantor_evidence WHERE content_sha256='' OR uploaded_by_id=''")->fetchColumn());
});
test('lost commit acknowledgement retains committed file and stable upload retry',function()use($pdf,$db,$private,$faultFlag){
 $before=(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn();file_put_contents($faultFlag,'fault');
 assertSameValue(500,evidenceUpload(1,'uncertain-commit-key10',$pdf,evidenceMember('borrower'))['status']);
 assertSameValue($before+1,(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn());$row=$db->query('SELECT * FROM loan_guarantor_evidence ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);assertSameValue(true,file_exists($private.'/'.$row['storage_key']));
 $r=evidenceUpload(1,'uncertain-commit-key10',$pdf,evidenceMember('borrower'));assertSameValue(200,$r['status']);assertSameValue($row['public_id'],evidenceData($r)['data']['public_id']);assertSameValue($before+1,(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn());
});
test('separate HTTP servers serialize concurrent identical and distinct upload commands',function()use($fixture,$web,$sessions,$environment,$log,$pdf,$db,$base,$private){
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);$secondPort=(int)substr(strrchr($address,':'),1);$secondBase='http://127.0.0.1:'.$secondPort;
 $secondServer=proc_open([PHP_BINARY,'-d','session.save_path='.$sessions,'-d','display_errors=0','-S','127.0.0.1:'.$secondPort,'-t',$web,$web.'/router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$secondPipes,$web,$environment);if(!is_resource($secondServer))throw new RuntimeException('Cannot start second HTTP server');fclose($secondPipes[0]);
 try{
  for($i=0;$i<100;$i++){if(@file_get_contents($secondBase.'/health')==='ready')break;usleep(20000);}
  file_put_contents($fixture.'/scanner.php','<?php return function($path){$content=file_get_contents($path);if(strpos($content,"SYNTHETIC-CONCURRENT")!==false)usleep(300000);return ["status"=>"clean","engine"=>"Synthetic concurrent scanner fixture"];};');
  file_put_contents($fixture.'/client.php', <<<'CLIENT'
<?php
$request=json_decode(base64_decode($argv[1]),true,512,JSON_THROW_ON_ERROR);
$context=stream_context_create(['http'=>['method'=>'POST','content'=>$request['body'],'header'=>implode("\r\n",$request['headers']),'ignore_errors'=>true,'timeout'=>15]]);
$body=file_get_contents($request['url'],false,$context);preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$status);echo json_encode(['status'=>(int)($status[1]??0),'body'=>$body],JSON_THROW_ON_ERROR);
CLIENT
  );
  $content=str_replace('trailer',"SYNTHETIC-CONCURRENT\ntrailer",$pdf);
  $run=function(array$keys)use($base,$secondBase,$fixture,$content){
   $workers=[];foreach([$base,$secondBase]as$i=>$origin){$wire=evidenceUpload(1,$keys[$i],$content,evidenceMember('borrower'),'approval','signed.pdf','application/pdf',true);$wire['url']=$origin.$wire['path'];$child=proc_open([PHP_BINARY,$fixture.'/client.php',base64_encode(json_encode($wire,JSON_THROW_ON_ERROR))],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$child,$pipes];}
   $results=[];foreach($workers as[$child,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($child)!==0)throw new RuntimeException('Concurrent client failed: '.$error);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}return$results;
  };
  $before=(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn();$files=count(glob($private.'/*'));
  $results=$run(['concurrent-shared-key11','concurrent-shared-key11']);foreach($results as$r)assertSameValue(200,$r['status']);assertSameValue(evidenceData($results[0])['data']['public_id'],evidenceData($results[1])['data']['public_id']);assertSameValue(true,evidenceData($results[1])['data']['is_latest']);assertSameValue($before+1,(int)$db->query('SELECT COUNT(*) FROM loan_guarantor_evidence')->fetchColumn());assertSameValue($files+1,count(glob($private.'/*')));
  $version=(int)$db->query("SELECT MAX(version_number) FROM loan_guarantor_evidence WHERE guarantor_request_id=1 AND evidence_kind='approval'")->fetchColumn();$results=$run(['concurrent-distinct-A11','concurrent-distinct-B11']);$versions=[];foreach($results as$r){assertSameValue(200,$r['status']);$versions[]=(int)evidenceData($r)['data']['version_number'];}sort($versions);assertSameValue([$version+1,$version+2],$versions);
 }finally{proc_terminate($secondServer);proc_close($secondServer);}
});
test('malformed pagination oversized JSON and cross-origin staff mutation fail safely',function()use($admin){
 foreach(['guarantor_request_id=1&limit=51','action%5B%5D=list&guarantor_request_id=1','guarantor_request_id%5B%5D=1']as$query)assertSameValue(400,evidenceHttp('/auth_api/api/admin/guarantor-evidence.php?'.$query,'GET','',$admin)['status']);
 assertSameValue(400,evidenceHttp('/auth_api/api/admin/guarantor-evidence.php','POST',str_repeat(' ',262145),array_merge($admin,['Content-Type: application/json']))['status']);
 $badOrigin=array_values(array_filter($admin,fn($header)=>!str_starts_with($header,'Origin:')));$badOrigin[]='Origin: https://outside.test';assertSameValue(403,evidenceHttp('/auth_api/api/admin/guarantor-evidence.php','POST','{}',array_merge($badOrigin,['Content-Type: application/json']))['status']);
});
test('private download refuses post-scan content tampering',function()use($db,$private,&$staffDocument){
 $stmt=$db->prepare('SELECT storage_key FROM loan_guarantor_evidence WHERE public_id=?');$stmt->execute([$staffDocument['public_id']]);$path=$private.'/'.$stmt->fetchColumn();$before=file_get_contents($path);file_put_contents($path,$before.'changed');
 try{$response=evidenceHttp('/auth_api/api/loans/guarantor-evidence.php?action=download&document_id='.$staffDocument['public_id'],'GET','',evidenceMember('borrower'));assertSameValue(500,$response['status']);assertSameValue(false,strpos($response['body'],'%PDF'));}finally{file_put_contents($path,$before);}
});
} finally {
 proc_terminate($process);proc_close($process);fclose($log);
 $resolved=str_replace('\\','/',realpath($fixture));$temporary=str_replace('\\','/',realpath(sys_get_temp_dir()));
 if(!str_starts_with(strtolower($resolved),strtolower($temporary.'/coop_evidence_')))throw new RuntimeException('Refusing cleanup outside the isolated test directory');
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($iterator as$entry){$path=$entry->getPathname();if($entry->isDir()&&!$entry->isLink())rmdir($path);else unlink($path);}rmdir($fixture);
}
finishTests();
