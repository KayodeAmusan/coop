<?php
require_once __DIR__.'/helpers.php';
if(($argv[1]??'')==='worker'){
 $fixture=$argv[2];$GLOBALS['platform_fixture_role']=$argv[3];http_response_code(200);ob_start();register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'body'=>ob_get_clean()],JSON_THROW_ON_ERROR);});require $fixture.'/platform_settings.php';exit;
}
$fixture=sys_get_temp_dir().'/coop_platform_ui_'.bin2hex(random_bytes(6));mkdir($fixture.'/includes',0700,true);copy(__DIR__.'/../platform_settings.php',$fixture.'/platform_settings.php');copy(__DIR__.'/../includes/software_credit.php',$fixture.'/includes/software_credit.php');file_put_contents($fixture.'/includes/auracle_platform_auth.php','<?php function requireAuraclePlatformPrincipal():array{if($GLOBALS["platform_fixture_role"]!=="platform")throw new RuntimeException("Platform login required",403);return["id"=>19,"username"=>"fixture-platform"]; }');
function platformPageRender($role){global$fixture;$p=proc_open([PHP_BINARY,__FILE__,'worker',$fixture,$role],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($error);return json_decode($body,true,512,JSON_THROW_ON_ERROR);}
test('ordinary cooperative administrator cannot render platform settings controls',function(){foreach(['Admin','Member','none']as$role){$response=platformPageRender($role);assertSameValue(302,$response['status']);assertSameValue(false,strpos($response['body'],'platform-branding-form')!==false);}});
$response=platformPageRender('platform');$dom=new DOMDocument();@$dom->loadHTML($response['body']);$xpath=new DOMXPath($dom);
test('platform identity renders labelled disabled-until-loaded branding and integration controls',function()use($response,$xpath){
 assertSameValue(200,$response['status']);assertSameValue(1,$xpath->query('//main[@data-actor-id="19"]')->length);assertSameValue(1,$xpath->query('//fieldset[@id="platform-branding-fields"][@disabled]')->length);
 foreach($xpath->query('//input|//select')as$control)assertSameValue(1,$xpath->query('//label[@for="'.$control->getAttribute('id').'"]')->length);
 assertSameValue(2,$xpath->query('//form[@enctype="multipart/form-data"]')->length);assertSameValue(3,$xpath->query('//input[@type="password"][@autocomplete="new-password"][@disabled]')->length);
});
test('platform editor exposes supported non-sending checks and explicit secret operations',function()use($response,$xpath){
 foreach(['smtp','paystack','sms']as$provider)foreach(['retain','replace','clear']as$action)assertSameValue(1,$xpath->query('//select[@id="'.$provider.'-secret-action"]/option[@value="'.$action.'"]')->length);
 assertSameValue(0,$xpath->query('//button[@data-test-provider="sms"]')->length);assertSameValue(2,$xpath->query('//button[@data-test-provider]')->length);assertContains('Sending remains unavailable',$response['body']);assertContains('Stored secrets are never shown',$response['body']);
});
unlink($fixture.'/platform_settings.php');unlink($fixture.'/includes/software_credit.php');unlink($fixture.'/includes/auracle_platform_auth.php');rmdir($fixture.'/includes');rmdir($fixture);finishTests();

