<?php
/** Real HTTP auth/preview tests in an isolated copied fixture. No application database/configuration is loaded. */
require_once __DIR__.'/helpers.php';
$fixture=__DIR__.'/../work/loan_product_http_fixture';
foreach(['api','includes','libs/services','config','sessions']as$dir)if(!is_dir($fixture.'/'.$dir))mkdir($fixture.'/'.$dir,0777,true);
foreach(['api/loan_product_drafts.php','includes/admin_access_helper.php','includes/money_helper.php','libs/services/LoanProductService.php','libs/services/LoanScheduleCalculator.php']as$file)copy(__DIR__.'/../'.$file,$fixture.'/'.$file);
file_put_contents($fixture.'/config/EnvConfig.php', '<?php class EnvConfig { public static function getDatabaseConfig(){throw new RuntimeException("PRIVATE SQL diagnostic must never escape");} }');
file_put_contents($fixture.'/test_login.php', '<?php session_start(); $_SESSION=["SESS_MEMBER_ID"=>($_GET["actor"]??"7"),"role"=>($_GET["role"]??"Admin")]; echo "{}";');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Cannot reserve local test port');$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);$base='http://127.0.0.1:'.$port;
$pipes=[];$process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','session.save_path='.$fixture.'/sessions','-S','127.0.0.1:'.$port,'-t',$fixture],[0=>['pipe','r'],1=>['file',$fixture.'/stdout.log','a'],2=>['file',$fixture.'/stderr.log','a']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot launch test server');fclose($pipes[0]);
for($i=0;$i<40;$i++){$ready=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,0.1);if($ready){fclose($ready);break;}usleep(50000);}
function draftHttp(string$path,string$method='GET',?array$body=null,string$cookie='',?string$origin=null):array{
    global$base;$headers=['Content-Type: application/json'];if($cookie!=='')$headers[]='Cookie: '.$cookie;if($origin!==null)$headers[]='Origin: '.$origin;
    $options=['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>5,'header'=>implode("\r\n",$headers)]];if($body!==null)$options['http']['content']=json_encode($body,JSON_THROW_ON_ERROR);
    $text=file_get_contents($base.$path,false,stream_context_create($options));$status=(int)explode(' ',$http_response_header[0])[1];$response=json_decode($text,true,512,JSON_THROW_ON_ERROR);
    return[$status,$response,$http_response_header];
}
function draftHttpLogin(string$actor,string$role):string{[$status,$body,$headers]=draftHttp('/test_login.php?actor='.urlencode($actor).'&role='.urlencode($role));foreach($headers as$header)if(str_starts_with(strtolower($header),'set-cookie:'))return explode(';',trim(substr($header,11)))[0];throw new RuntimeException('No fixture session');}
try{
    test('real HTTP denies anonymous and nonadmin catalog reads before database',function(){assertSameValue(403,draftHttp('/api/loan_product_drafts.php')[0]);$cookie=draftHttpLogin('7','Member');assertSameValue(403,draftHttp('/api/loan_product_drafts.php','GET',null,$cookie)[0]);});
    test('real HTTP rejects malformed trusted actor before database',function(){$cookie=draftHttpLogin('not-a-staff-id','Admin');assertSameValue(403,draftHttp('/api/loan_product_drafts.php','GET',null,$cookie)[0]);});
    $cookie=draftHttpLogin('7','Admin');
    $inputs=['principal'=>'1.00','method'=>'interest_free','installment_count'=>1,'rounding_mode'=>'half_up','residual_treatment'=>'adjust_final_installment','fees'=>['handling'=>'none']];
    test('real HTTP rejects cross-origin mutations',function()use($cookie,$inputs){assertSameValue(403,draftHttp('/api/loan_product_drafts.php','POST',['action'=>'preview','calculation_inputs'=>$inputs],$cookie,'https://unrelated.invalid')[0]);});
    test('real HTTP preview succeeds without database and preserves exact strings',function()use($cookie,$inputs,$base){[$status,$response]=draftHttp('/api/loan_product_drafts.php','POST',['action'=>'preview','calculation_inputs'=>$inputs],$cookie,$base);assertSameValue(200,$status);assertSameValue('1.00',$response['data']['total_repayable']);assertSameValue(true,$response['draft_only']);assertSameValue(false,$response['applications_enabled']);});
    test('real HTTP refuses publish and non-POST mutations',function()use($cookie,$base){assertSameValue(400,draftHttp('/api/loan_product_drafts.php','POST',['action'=>'publish','idempotency_key'=>'not-supported'],$cookie,$base)[0]);assertSameValue(405,draftHttp('/api/loan_product_drafts.php','PUT',null,$cookie,$base)[0]);assertSameValue(400,draftHttp('/api/loan_product_drafts.php?action=create_draft','GET',null,$cookie)[0]);});
    test('real HTTP private database/config errors return generic JSON',function()use($cookie){[$status,$response]=draftHttp('/api/loan_product_drafts.php','GET',null,$cookie);assertSameValue(500,$status);assertSameValue(false,$response['success']);assertSameValue(false,str_contains(json_encode($response),'PRIVATE SQL'));assertContains('Unable to complete',$response['message']);});
}finally{proc_terminate($process);proc_close($process);}
finishTests();
