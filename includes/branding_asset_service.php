<?php
require_once __DIR__.'/guarantor_evidence_config.php';
class BrandingAssetService{
 private $db;private PrivateDocumentStorage $storage;
 public function __construct($db,PrivateDocumentStorage $storage){$this->db=$db;$this->storage=$storage;}
 public function upload(array $principal,string $kind,array $file,string $key):array{
  if(!isset($principal['id'])||!in_array($kind,['logo','favicon'],true)||!preg_match('/\A[A-Za-z0-9_-]{16,128}\z/',$key))throw new InvalidArgumentException('Invalid platform branding upload');
  if($this->db->inTransaction())throw new LogicException('Branding upload owns its transaction');$stored=null;$commitAttempted=false;$this->db->beginTransaction();
  try{$this->db->query('SELECT id FROM coop_deployment_branding WHERE id=1 FOR UPDATE')->fetchColumn();$stored=$this->storage->storeUploadedFile($file);
   if(!in_array($stored['mime_type'],$kind==='favicon'?['image/png']:['image/png','image/jpeg'],true))throw new InvalidArgumentException('Use JPEG or PNG for logos and PNG for favicons');
   $hash=hash('sha256',json_encode(['branding_asset',$principal['id'],$kind,$stored['content_sha256']],JSON_THROW_ON_ERROR));$keyHash=hash('sha256',$principal['id'].':'.$key);
   $stmt=$this->db->prepare('SELECT * FROM coop_platform_configuration_commands WHERE key_hash=?');$stmt->execute([$keyHash]);$prior=$stmt->fetch(PDO::FETCH_ASSOC);
   if($prior){if(!hash_equals($prior['request_hash'],$hash))throw new RuntimeException('Branding upload key conflicts with different content',409);$result=json_decode($prior['response_json'],true,512,JSON_THROW_ON_ERROR);$this->storage->remove($stored['storage_key']);$stored=null;$commitAttempted=true;$this->db->commit();return$result;}
   if($stored['scan_status']!=='clean')throw new RuntimeException('Branding images require the configured malware scanner before publication',409);
   $id=bin2hex(random_bytes(16));$stmt=$this->db->prepare('INSERT INTO coop_branding_assets(asset_id,asset_kind,storage_key,content_sha256,byte_size,mime_type,scan_status,scan_engine,uploaded_by)VALUES(?,?,?,?,?,?,?,?,?)');$stmt->execute([$id,$kind,$stored['storage_key'],$stored['content_sha256'],$stored['byte_size'],$stored['mime_type'],$stored['scan_status'],$stored['scan_engine'],(string)$principal['id']]);
   $result=['asset_id'=>$id,'url'=>'api/branding_asset.php?id='.$id];$stmt=$this->db->prepare('INSERT INTO coop_platform_configuration_commands(key_hash,request_hash,response_json)VALUES(?,?,?)');$stmt->execute([$keyHash,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
   $stmt=$this->db->prepare("INSERT INTO coop_platform_configuration_history(platform_actor_id,event_type,redacted_metadata)VALUES(?,'branding_asset_uploaded',?)");$stmt->execute([(string)$principal['id'],json_encode(['asset_id'=>$id,'kind'=>$kind,'content_sha256'=>$stored['content_sha256']],JSON_THROW_ON_ERROR)]);$commitAttempted=true;$this->db->commit();return$result;
  }catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();if($stored&&!$commitAttempted)$this->storage->remove($stored['storage_key']);throw$error;}
 }
 public function serve(string $id):void{
  if(!preg_match('/\A[a-f0-9]{32}\z/',$id))throw new RuntimeException('Branding asset not found',404);$stmt=$this->db->prepare('SELECT * FROM coop_branding_assets WHERE asset_id=?');$stmt->execute([$id]);$asset=$stmt->fetch(PDO::FETCH_ASSOC);
  if(!$asset||!in_array($asset['mime_type'],['image/png','image/jpeg'],true))throw new RuntimeException('Branding asset not found',404);$handle=$this->storage->openCleanDocument($asset);
  header('Content-Type: '.$asset['mime_type']);header('Content-Length: '.$asset['byte_size']);header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");header('Cache-Control: public, max-age=86400, immutable');fpassthru($handle);fclose($handle);
 }
}
