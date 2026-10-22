<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../auth_api/utils/EmailService.php';
require_once __DIR__.'/../config/EnvConfig.php';
if(getenv('COOP_PLATFORM_MYSQL_TEST')!=='1') { fwrite(STDERR,"Set COOP_PLATFORM_MYSQL_TEST=1 for disposable mail configuration tests.\n"); exit(2); }
$server=new PDO('mysql:host=127.0.0.1;port=13307;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS coop_test_mail_runtime');
$db=new PDO('mysql:host=127.0.0.1;port=13307;dbname=coop_test_mail_runtime;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['auracle_platform_admins','auracle_platform_login_limits','coop_deployment_branding','coop_integration_configuration','coop_platform_configuration_history','coop_platform_configuration_commands','coop_branding_assets'] as $table) $db->exec('DROP TABLE IF EXISTS '.$table);
$db->exec(file_get_contents(__DIR__.'/../database/migrations/20261021_platform_configuration.sql'));
$keyFile=tempnam(sys_get_temp_dir(),'coop_mail_key_');$key=random_bytes(32);file_put_contents($keyFile,base64_encode($key));chmod($keyFile,0600);
$previous=$_ENV;$_ENV['PLATFORM_CONFIG_KEY_FILE']=$keyFile;
$service=new DeploymentSettingsService($db,new DeploymentSecretCipher($key),fn($principal)=>($principal['id']??null)===7);
$principal=['id'=>7];$smtp=['host'=>'smtp.example.invalid','port'=>587,'encryption'=>'starttls','username'=>'synthetic-user','from_email'=>'sender@example.invalid','from_name'=>'Synthetic mail cooperative'];
try {
 test('encrypted administrator configuration reaches actual security mailer without network traffic',function()use($db,$service,$principal,$smtp){
  $service->saveIntegration($principal,'smtp',0,'mail-runtime-save-command-0001',true,$smtp,['password'=>['action'=>'replace','value'=>'synthetic-runtime-secret']]);
  $mailService=new EmailService($db);
  $mail=(new ReflectionProperty(EmailService::class,'mailer'))->getValue($mailService);
  assertSameValue('smtp.example.invalid',$mail->Host);assertSameValue('synthetic-runtime-secret',$mail->Password);
  assertSameValue(587,$mail->Port);assertSameValue('tls',$mail->SMTPSecure);assertSameValue('sender@example.invalid',$mail->From);
  $stored=$db->query("SELECT encrypted_secrets FROM coop_integration_configuration WHERE provider='smtp'")->fetchColumn();
  assertSameValue(false,str_contains($stored,'synthetic-runtime-secret'));
 });
 test('updated SMTP revision is used by newly constructed senders',function()use($db,$service,$principal,$smtp){
  $smtp['port']=465;$smtp['encryption']='tls';$smtp['from_name']='Changed synthetic sender';
  $service->saveIntegration($principal,'smtp',1,'mail-runtime-update-command-02',true,$smtp,[]);
  $config=EnvConfig::getMailConfig($db);assertSameValue(465,$config['port']);assertSameValue('ssl',$config['encryption']);
  assertSameValue('Changed synthetic sender',$config['from_name']);assertSameValue('synthetic-runtime-secret',$config['password']);
 });
 test('disabled persisted SMTP cannot fall back to another sender configuration',function()use($db,$service,$principal,$smtp){
  $service->saveIntegration($principal,'smtp',2,'mail-runtime-disable-command03',false,$smtp,[]);
  try { new EmailService($db);throw new LogicException('Disabled SMTP accepted'); }
  catch(RuntimeException $error){assertContains('disabled',$error->getMessage());}
 });
} finally { $_ENV=$previous;unlink($keyFile); }
finishTests();
