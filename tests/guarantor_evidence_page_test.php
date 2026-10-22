<?php
require_once __DIR__.'/helpers.php';
function deploymentPublicBranding(?PDO $db=null):array{return ['configured'=>false];}
if(($argv[1]??'')==='worker'){
 $_SERVER['PHP_SELF']='/guarantor_evidence_review.php';$_GET['loan_request_id']=$argv[3]??'';
 session_save_path(sys_get_temp_dir());session_id('evidenceuitest'.bin2hex(random_bytes(6)));session_start();
 $_SESSION=($argv[2]??'')==='admin'?['SESS_MEMBER_ID'=>'7','role'=>'Admin']:['SESS_MEMBER_ID'=>'7','role'=>'Member'];
 http_response_code(200);ob_start();register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'body'=>ob_get_clean()],JSON_THROW_ON_ERROR);});
 require __DIR__.'/../guarantor_evidence_review.php';exit;
}
function evidencePageRender($role,$loan=''){
 $process=proc_open([PHP_BINARY,__FILE__,'worker',$role,$loan],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Cannot render evidence page');$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
 if($code!==0)throw new RuntimeException($error);return json_decode($body,true,512,JSON_THROW_ON_ERROR);
}
test('evidence staff UI rejects non-admin before any private form markup',function(){
 $response=evidencePageRender('member');assertSameValue(403,$response['status']);assertSameValue(false,strpos($response['body'],'evidence-upload-form')!==false);
});
$response=evidencePageRender('admin');$dom=new DOMDocument();@$dom->loadHTML($response['body']);$xpath=new DOMXPath($dom);
test('evidence UI has labelled multipart private upload and explicit category',function()use($response,$xpath){
 assertSameValue(200,$response['status']);assertSameValue(1,$xpath->query('//form[@id="evidence-upload-form"][@enctype="multipart/form-data"]')->length);
 assertSameValue(1,$xpath->query('//input[@type="file"][@name="evidence"][@required]')->length);
 foreach($xpath->query('//section[@id="guarantor-evidence-review"]//input|//section[@id="guarantor-evidence-review"]//select')as$control)assertSameValue(1,$xpath->query('//label[@for="'.$control->getAttribute('id').'"]')->length);
 assertSameValue(1,$xpath->query('//select[@id="evidence-kind"]/option[@value=""][@selected]')->length);
 assertSameValue(1,$xpath->query('//section[@id="evidence-upload-section"][@hidden]')->length);
 assertSameValue(1,$xpath->query('//section[@id="guarantor-evidence-review"][@data-reviewer-id="7"]')->length);
});
test('evidence UI states online click and acknowledgement do not substitute signed approval',function()use($response){
 assertContains('An online response alone is insufficient',$response['body']);assertContains('Acknowledgement files are optional',$response['body']);
 assertContains('newer pending, quarantined or rejected version prevents an older accepted',$response['body']);
 assertContains('This screen never approves or disburses the loan',$response['body']);
 assertContains('Maximum file size: 5 MiB',$response['body']);
});
test('loan context query is exact and cannot inject an association or markup',function(){
 $good=evidencePageRender('admin','123');assertContains('value="123"',$good['body']);
 foreach(['123junk','2147483648','"><img src=x onerror=bad()>']as$value){
  $bad=evidencePageRender('admin',$value);$dom=new DOMDocument();@$dom->loadHTML($bad['body']);$xpath=new DOMXPath($dom);assertSameValue('',$xpath->query('//input[@id="evidence-loan-id"]')->item(0)->getAttribute('value'));
  assertSameValue(0,$xpath->query('//section[@id="guarantor-evidence-review"]//img')->length);
 }
});
finishTests();
