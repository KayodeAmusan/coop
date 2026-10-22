<?php
require_once __DIR__.'/helpers.php';
function deploymentPublicBranding(?PDO $db=null):array{return ['configured'=>false];}
ob_start();require __DIR__.'/../member_guarantor_evidence.php';$body=ob_get_clean();
$dom=new DOMDocument();@$dom->loadHTML($body);$xpath=new DOMXPath($dom);
test('member evidence page renders without staff session or database access',function()use($body,$xpath){
 assertSameValue(1,$xpath->query('//section[@id="guarantor-evidence-review"][@data-member="true"]')->length);
 assertSameValue(1,$xpath->query('//div[@id="member-workspace"][@hidden]')->length);
 assertSameValue(false,strpos($body,'includes/header.php')!==false);
 assertSameValue(false,strpos($body,'data-reviewer-id="7"')!==false);
});
test('member sign-in uses accessible native username and password fields',function()use($xpath){
 foreach(['member-username','member-password']as$id)assertSameValue(1,$xpath->query('//label[@for="'.$id.'"]')->length);
 assertSameValue(1,$xpath->query('//input[@id="member-username"][@autocomplete="username"][@required]')->length);
 assertSameValue(1,$xpath->query('//input[@id="member-password"][@type="password"][@autocomplete="current-password"][@required]')->length);
 assertSameValue(0,$xpath->query('//input[contains(@id,"token")]')->length);
});
test('member and staff share the actual upload/history view with explicit category and retry reset',function()use($xpath){
 assertSameValue(1,$xpath->query('//form[@id="evidence-upload-form"][@enctype="multipart/form-data"]')->length);
 assertSameValue(1,$xpath->query('//select[@id="evidence-kind"]/option[@value=""][@selected]')->length);
 assertSameValue(1,$xpath->query('//button[@id="evidence-new-upload"][@type="button"]')->length);
 assertSameValue(1,$xpath->query('//script[@src="assets/js/guarantor_evidence.js"]')->length);
});
finishTests();
