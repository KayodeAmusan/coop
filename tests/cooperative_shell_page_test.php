<?php
require_once __DIR__.'/helpers.php';
function deploymentPublicBranding(?PDO $db=null):array{return ['configured'=>false];}
if(($argv[1]??'')==='worker'){
 $_SERVER['PHP_SELF']='/home.php';session_save_path(sys_get_temp_dir());session_id('shelluitest'.bin2hex(random_bytes(6)));session_start();
 $_SESSION=['SESS_MEMBER_ID'=>'7','SESS_FIRST_NAME'=>'<img src=x onerror=bad()>','role'=>($argv[2]??'user')];
 ob_start();require __DIR__.'/../includes/header.php';echo '<h1>Actual test content</h1>';require __DIR__.'/../includes/footer.php';echo ob_get_clean();exit;
}
function shellRender($role){$p=proc_open([PHP_BINARY,__FILE__,'worker',$role],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($error);return$body;}
$body=shellRender('Admin');$dom=new DOMDocument();@$dom->loadHTML($body);$xpath=new DOMXPath($dom);
test('shared shell loads local token and component styles with skip navigation',function()use($xpath){
 foreach(['cooperative-theme.css','cooperative.css','cooperative-components.css']as$file)assertSameValue(1,$xpath->query('//link[@href="assets/css/'.$file.'"]')->length);
 assertSameValue(1,$xpath->query('//a[@href="#main-content"]')->length);assertSameValue(1,$xpath->query('//main[@id="main-content"]')->length);
 assertSameValue(1,$xpath->query('//button[@id="menu-toggle"][@aria-controls="sidebar"][@aria-expanded="false"]')->length);
});
test('shell preserves authorized navigation and escapes user text',function()use($xpath){
 assertSameValue(1,$xpath->query('//aside//a[@href="loan-request-admin.php"]')->length);
 assertSameValue(1,$xpath->query('//aside//a[@href="member_guarantor_evidence.php"]')->length);
 assertSameValue(0,$xpath->query('//header//img')->length);
 $member=shellRender('Member');assertSameValue(false,strpos($member,'href="loan-request-admin.php"')!==false);
});
test('provided software vendor credit is linked without an invented logo',function()use($xpath){
 assertSameValue(1,$xpath->query('//footer//a[@href="https://www.auracle.ng"][text()="Auracle Innovations Limited"]')->length);
});
finishTests();
