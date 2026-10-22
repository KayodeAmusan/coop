<?php
require_once __DIR__.'/helpers.php';
function deploymentPublicBranding(?PDO $db=null):array{return ['configured'=>false];}
if(($argv[1]??'')==='worker'){
 $_SERVER['PHP_SELF']='/loan_product_setup.php';session_save_path(sys_get_temp_dir());session_id('loanproductuitest'.bin2hex(random_bytes(6)));session_start();
 $_SESSION=($argv[2]??'')==='admin'?['SESS_MEMBER_ID'=>'7','role'=>'Admin']:['SESS_MEMBER_ID'=>'7','role'=>'Member'];
 http_response_code(200);ob_start();register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'body'=>ob_get_clean()],JSON_THROW_ON_ERROR);});
 require __DIR__.'/../loan_product_setup.php';exit;
}
function loanSetupRender($role){
 $process=proc_open([PHP_BINARY,__FILE__,'worker',$role],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Cannot run page fixture');$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
 if($code!==0)throw new RuntimeException($errors);return json_decode($output,true,512,JSON_THROW_ON_ERROR);
}
test('draft setup denies non-admin before private page markup',function(){
 $response=loanSetupRender('member');assertSameValue(403,$response['status']);assertSameValue(false,strpos($response['body'],'draft-calculation-form')!==false);
});
$response=loanSetupRender('admin');$dom=new DOMDocument();@$dom->loadHTML($response['body']);$xpath=new DOMXPath($dom);
test('draft setup has no financial defaults and exposes all six methods',function()use($response,$xpath){
 assertSameValue(200,$response['status']);
 foreach(['calculation-method','calculation-rounding','calculation-residual','calculation-fees','calculation-rate-basis']as$id){
  assertSameValue(1,$xpath->query('//select[@id="'.$id.'"]/option[@value=""][@selected]')->length);
  assertSameValue(0,$xpath->query('//select[@id="'.$id.'"]/option[@value!=""][@selected]')->length);
 }
 foreach(['calculation-principal','calculation-count','calculation-rate','calculation-manual-interest']as$id)assertSameValue(0,$xpath->query('//input[@id="'.$id.'"][@value!=""]')->length);
 foreach(['interest_free','flat','reducing_equal_installments','reducing_equal_principal','interest_only_balloon','manual_schedule']as$method)assertSameValue(1,$xpath->query('//select[@id="calculation-method"]/option[@value="'.$method.'"]')->length);
});
test('draft setup labels every input and retains keyboard-accessible schedule region',function()use($xpath){
 foreach($xpath->query('//section[@id="loan-product-setup"]//input|//section[@id="loan-product-setup"]//select|//section[@id="loan-product-setup"]//textarea')as$control){
  assertSameValue(1,$xpath->query('//label[@for="'.$control->getAttribute('id').'"]')->length);
 }
 assertSameValue(1,$xpath->query('//div[@role="region"][@tabindex="0"][@aria-label="Numbered calculation installments"]')->length);
 assertSameValue(6,$xpath->query('//table//th[@scope="col"]')->length);
});
test('draft setup discloses preview boundary unsupported fees and unauthenticated manual review',function()use($response,$xpath){
 assertContains('New loan applications remain disabled',$response['body']);assertContains('does not prove an authenticated staff review',$response['body']);
 assertContains('no due dates',$response['body']);assertContains('never silently treated as zero',$response['body']);
 assertSameValue(1,$xpath->query('//select[@id="calculation-fees"]/option[@value="unsupported"]')->length);
 foreach($xpath->query('//section[@id="loan-product-setup"]//button')as$button)assertSameValue(false,stripos($button->textContent,'publish')!==false);
});
finishTests();
