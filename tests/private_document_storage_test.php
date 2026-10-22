<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../includes/private_document_storage.php';
require_once __DIR__.'/../includes/guarantor_evidence_config.php';
$fixture=sys_get_temp_dir().'/coop_private_storage_'.bin2hex(random_bytes(6));
foreach(['','/public','/public/app','/public/sibling','/private']as$part)mkdir($fixture.$part,0700);
$public=$fixture.'/public';$app=$public.'/app';$private=$fixture.'/private';$store=new PrivateDocumentStorage($private,$public,null,$app);
$key=bin2hex(random_bytes(32));$bytes="%PDF-1.4\n%%EOF\n";file_put_contents($private.'/'.$key,$bytes);
$metadata=['storage_key'=>$key,'content_sha256'=>hash('sha256',$bytes),'byte_size'=>strlen($bytes),'mime_type'=>'application/pdf','scan_status'=>'clean'];
$previousServer=$_SERVER;$previousEnv=$_ENV;
try{
 test('private storage rejects missing relative and web-accessible roots',function()use($private,$public,$app,$fixture){
  foreach(['','relative-path',$public,$public.'/sibling',$fixture]as$root){try{new PrivateDocumentStorage($root,$public,null,$app);throw new LogicException('Unsafe root accepted');}catch(RuntimeException $error){assertSameValue(true,true);}}
 });
 test('CLI local files cannot impersonate HTTP uploaded files',function()use($store,$private,$key){
  try{$store->storeUploadedFile(['tmp_name'=>$private.'/'.$key,'error'=>UPLOAD_ERR_OK,'size'=>1,'type'=>'application/pdf']);throw new LogicException('Nonupload accepted');}catch(InvalidArgumentException $error){assertContains('successful file upload',$error->getMessage());}
 });
 test('missing scanner quarantines bytes and cannot authorize download',function()use($store,$metadata){
  $result=$store->scan($metadata);assertSameValue('quarantined',$result['scan_status']);assertSameValue(null,$result['scan_engine']);
  try{$store->openCleanDocument(array_merge($metadata,$result));throw new LogicException('Quarantined file downloaded');}catch(RuntimeException $error){assertContains('quarantined',$error->getMessage());}
 });
 test('scanner exceptions malformed responses and unavailable results remain quarantined',function()use($private,$public,$app,$metadata){
  foreach([fn($path)=>true,fn($path)=>['status'=>'clean'],fn($path)=>['status'=>'unavailable','engine'=>'Synthetic unavailable adapter'],function($path){throw new RuntimeException('Offline');}]as$scanner){$storage=new PrivateDocumentStorage($private,$public,$scanner,$app);assertSameValue('quarantined',$storage->scan($metadata)['scan_status']);}
 });
 test('stored hash and actual length are checked before reading',function()use($store,$metadata,$bytes,$private,$key){
  $handle=$store->openCleanDocument($metadata);assertSameValue($bytes,stream_get_contents($handle));fclose($handle);
  file_put_contents($private.'/'.$key,$bytes.'tampered');
  try{$store->openCleanDocument($metadata);throw new LogicException('Changed file accepted');}catch(RuntimeException $error){assertContains('integrity',$error->getMessage());}finally{file_put_contents($private.'/'.$key,$bytes);}
 });
 test('opaque keys prevent traversal and arbitrary deletion',function()use($store,$metadata){
  foreach(['../outside',str_repeat('a',63),str_repeat('G',64)]as$key){try{$store->remove($key);throw new LogicException('Unsafe key accepted');}catch(InvalidArgumentException $error){assertContains('storage key',$error->getMessage());}}
 });
 test('actual document root parent blocks a sibling storage directory',function()use($public){
  $_SERVER['DOCUMENT_ROOT']=$public;$_ENV['PRIVATE_DOCUMENT_STORAGE_ROOT']=$public.'/sibling';
  try{configuredGuarantorEvidenceStorage();throw new LogicException('Public sibling storage accepted');}catch(RuntimeException $error){assertContains('document root',$error->getMessage());}
 });
 test('scanner adapters inside storage or web root are never executed',function()use($private,$public,$fixture){
  $marker=$fixture.'/executed';$adapter=$private.'/adapter.php';file_put_contents($adapter,'<?php file_put_contents('.var_export($marker,true).',"bad"); return fn($path)=>["status"=>"clean","engine"=>"unsafe"];');
  $_SERVER['DOCUMENT_ROOT']=$public;$_ENV['PRIVATE_DOCUMENT_STORAGE_ROOT']=$private;$_ENV['DOCUMENT_SCANNER_ADAPTER']=$adapter;configuredGuarantorEvidenceStorage();assertSameValue(false,file_exists($marker));
  copy($adapter,$public.'/adapter.php');$_ENV['DOCUMENT_SCANNER_ADAPTER']=$public.'/adapter.php';configuredGuarantorEvidenceStorage();assertSameValue(false,file_exists($marker));unlink($public.'/adapter.php');unlink($adapter);
 });
}finally{
 $_SERVER=$previousServer;$_ENV=$previousEnv;
 $resolved=str_replace('\\','/',realpath($fixture));$temporary=str_replace('\\','/',realpath(sys_get_temp_dir()));
 if(!str_starts_with(strtolower($resolved),strtolower($temporary.'/coop_private_storage_')))throw new RuntimeException('Refusing cleanup outside the isolated test directory');
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as$entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($fixture);
}
finishTests();
