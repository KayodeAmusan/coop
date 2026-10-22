<?php
/** AES-GCM binds each ciphertext to its provider/field; the deployment key never enters the database. */
class DeploymentSecretCipher {
 private string $encryptionKey;
 private string $fingerprintKey;
 public function __construct(string $key){
  if(strlen($key)!==32)throw new RuntimeException('A 32-byte deployment encryption key is required');
  $this->encryptionKey=hash_hkdf('sha256',$key,32,'auracle-settings-encryption-v1');
  $this->fingerprintKey=hash_hkdf('sha256',$key,32,'auracle-settings-command-v1');
 }
 public function seal(string $secret,string $context):string{
  $nonce=random_bytes(12);$tag='';$ciphertext=openssl_encrypt($secret,'aes-256-gcm',$this->encryptionKey,OPENSSL_RAW_DATA,$nonce,$tag,$context,16);
  if($ciphertext===false)throw new RuntimeException('Credential encryption failed');
  return json_encode(['v'=>1,'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($ciphertext)],JSON_THROW_ON_ERROR);
 }
 public function open(string $envelope,string $context):string{
  $data=json_decode($envelope,true);if(!is_array($data)||($data['v']??null)!==1)throw new RuntimeException('Stored credential could not be verified');
  foreach(['nonce','tag','ciphertext']as$field){if(!is_string($data[$field]??null)||($data[$field]=base64_decode($data[$field],true))===false)throw new RuntimeException('Stored credential could not be verified');}
  if(strlen($data['nonce'])!==12||strlen($data['tag'])!==16)throw new RuntimeException('Stored credential could not be verified');
  $secret=openssl_decrypt($data['ciphertext'],'aes-256-gcm',$this->encryptionKey,OPENSSL_RAW_DATA,$data['nonce'],$data['tag'],$context);
  if($secret===false)throw new RuntimeException('Stored credential could not be verified');return$secret;
 }
 public function fingerprint(string $request):string{return hash_hmac('sha256',$request,$this->fingerprintKey);}
}
function deploymentSettingsCipher():DeploymentSecretCipher{
 require_once __DIR__.'/private_document_storage.php';require_once __DIR__.'/../config/EnvConfig.php';
 $configured=$_ENV['PLATFORM_CONFIG_KEY_FILE']??getenv('PLATFORM_CONFIG_KEY_FILE')?:EnvConfig::get('PLATFORM_CONFIG_KEY_FILE','');
 $absolute=is_string($configured)&&preg_match('~^(?:[A-Za-z]:/|/)~',str_replace('\\','/',$configured));
 $path=$absolute?realpath($configured):false;$app=realpath(__DIR__.'/..');$public=$app;
 if(isset($_SERVER['DOCUMENT_ROOT'])&&$_SERVER['DOCUMENT_ROOT']!==''){$public=is_string($_SERVER['DOCUMENT_ROOT'])?realpath($_SERVER['DOCUMENT_ROOT']):false;if(!$public)throw new RuntimeException('Deployment document root could not be verified');}
 if(!$path||!is_file($path)||!is_readable($path)||PrivateDocumentStorage::isWithin($path,$app)||PrivateDocumentStorage::isWithin($path,$public))throw new RuntimeException('Private deployment encryption key is not configured');
 $storage=$_ENV['PRIVATE_DOCUMENT_STORAGE_ROOT']??getenv('PRIVATE_DOCUMENT_STORAGE_ROOT')?:EnvConfig::get('PRIVATE_DOCUMENT_STORAGE_ROOT','');
 if(is_string($storage)&&$storage!==''&&($storagePath=realpath($storage))&&PrivateDocumentStorage::isWithin($path,$storagePath))throw new RuntimeException('Deployment encryption key must be outside uploaded document storage');
 if(PHP_OS_FAMILY!=='Windows'&&(fileperms($path)&0077)!==0)throw new RuntimeException('Deployment encryption key permissions must be private');
 $encoded=file_get_contents($path,false,null,0,256);$key=is_string($encoded)?base64_decode(trim($encoded),true):false;
 if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('Deployment encryption key must encode 32 random bytes');
 return new DeploymentSecretCipher($key);
}
