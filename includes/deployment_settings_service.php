<?php
require_once __DIR__.'/deployment_secret_cipher.php';
class DeploymentSettingsService{
 private $db;private ?DeploymentSecretCipher $cipher;private $authorize;
 public function __construct($db,?DeploymentSecretCipher $cipher,callable $authorize){$this->db=$db;$this->cipher=$cipher;$this->authorize=$authorize;}
 private function actor(array $principal):string{if(($this->authorize)($principal)!==true||(!is_string($principal['id']??null)&&!is_int($principal['id']??null))||trim((string)$principal['id'])==='')throw new RuntimeException('Auracle platform administration is required',403);return(string)$principal['id'];}
 public static function branding(array $row):array{
  $result=array_intersect_key($row,array_flip(['revision','cooperative_name','primary_color','accent_color','logo_asset_id','favicon_asset_id']));$result['revision']=(int)($row['revision']??0);$result['configured']=$result['revision']>0;
  foreach(['logo','favicon']as$kind)$result[$kind.'_url']=!empty($row[$kind.'_asset_id'])?'api/branding_asset.php?id='.$row[$kind.'_asset_id']:null;
  return$result;
 }
 public function settings(array $principal):array{$this->actor($principal);$row=$this->db->query('SELECT * FROM coop_deployment_branding WHERE id=1')->fetch(PDO::FETCH_ASSOC);$integrations=[];
  foreach(['smtp','paystack','sms']as$provider){$stmt=$this->db->prepare('SELECT * FROM coop_integration_configuration WHERE provider=?');$stmt->execute([$provider]);$integrations[$provider]=$this->masked($provider,$stmt->fetch(PDO::FETCH_ASSOC)?:null);}
  return['branding'=>self::branding($row),'integrations'=>$integrations,'sms_providers'=>[['id'=>'custom_http','label'=>'Custom HTTPS API','capability'=>'configuration_only']]];
 }
 private function masked(string $provider,?array $row):array{
  $secretNames=['smtp'=>['password'],'paystack'=>['secret_key'],'sms'=>['api_key']][$provider];$stored=$row?json_decode($row['encrypted_secrets'],true,512,JSON_THROW_ON_ERROR):[];$states=[];
  foreach($secretNames as$name)$states[$name]=['configured'=>isset($stored[$name]),'masked'=>isset($stored[$name])?'********':null];
  return['revision'=>$row?(int)$row['revision']:0,'enabled'=>$row?(bool)$row['enabled']:false,'config'=>$row?json_decode($row['config_json'],true,512,JSON_THROW_ON_ERROR):(object)[],'secrets'=>$states];
 }
 private function text($value,int $max,bool $required=true):string{if(!is_string($value)||strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value)||($required&&trim($value)===''))throw new InvalidArgumentException('Invalid configuration field');return trim($value);}
 private function config(string $provider,array $input):array{
  $fields=['smtp'=>['host','port','encryption','username','from_email','from_name'],'paystack'=>['mode','public_key'],'sms'=>['adapter','endpoint_url','http_method','auth_style','auth_field','sender_id','recipient_field','message_field','sender_field']];
  if(!isset($fields[$provider])||array_diff(array_keys($input),$fields[$provider]))throw new InvalidArgumentException('Unsupported integration configuration fields');
  $out=[];foreach($fields[$provider]as$field)$out[$field]=$field==='port'?($input[$field]??null):$this->text($input[$field]??'',512,!in_array($field,['username','auth_field','sender_id','sender_field'],true));
  if($provider==='smtp'){
   if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9.-]{0,252}\z/',$out['host'])||!is_int($out['port'])||$out['port']<1||$out['port']>65535||!in_array($out['encryption'],['starttls','tls'],true)||!filter_var($out['from_email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Invalid SMTP host, port, TLS mode or sender email');
  }elseif($provider==='paystack'){
   if(!in_array($out['mode'],['test','live'],true)||!preg_match('/\Apk_'.preg_quote($out['mode'],'/').'_[A-Za-z0-9]+\z/',$out['public_key']))throw new InvalidArgumentException('Select matching Paystack mode and public key');
  }else{
   $url=parse_url($out['endpoint_url']);if($out['adapter']!=='custom_http'||!$url||($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'],$url['pass'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!in_array($out['http_method'],['GET','POST'],true)||!in_array($out['auth_style'],['bearer','header','body'],true))throw new InvalidArgumentException('Configure a custom HTTPS SMS API without URL credentials or executable templates');
   if(filter_var(trim($url['host'],'[]'),FILTER_VALIDATE_IP)&&!filter_var(trim($url['host'],'[]'),FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new InvalidArgumentException('Private or reserved SMS destinations are not permitted');
   if(in_array(strtolower($url['host']),['localhost','localhost.localdomain'],true)||str_ends_with(strtolower($url['host']),'.local'))throw new InvalidArgumentException('Private SMS destinations are not permitted');
   foreach(['auth_field','recipient_field','message_field','sender_field']as$field){if($out[$field]!==''&&!preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]{0,63}\z/',$out[$field]))throw new InvalidArgumentException('Invalid SMS request field mapping');}
   if($out['auth_style']!=='bearer'&&$out['auth_field']==='')throw new InvalidArgumentException('Specify the SMS authentication field');
  }return$out;
 }
 private function mutate(array $principal,string $key,array $request,callable $operation,bool $sensitive=false):array{
  $actor=$this->actor($principal);if(!preg_match('/\A[A-Za-z0-9_-]{16,128}\z/',$key))throw new InvalidArgumentException('A stable configuration command key is required');
  $serialized=json_encode($request,JSON_THROW_ON_ERROR);if($sensitive&&!$this->cipher)throw new RuntimeException('Configure the private deployment encryption key first');$hash=$sensitive?$this->cipher->fingerprint($serialized):hash('sha256',$serialized);$keyHash=hash('sha256',$actor.':'.$key);
  if($this->db->inTransaction())throw new LogicException('Platform configuration owns its transaction');$this->db->beginTransaction();
  try{if($this->db->query('SELECT id FROM coop_deployment_branding WHERE id=1 FOR UPDATE')->fetchColumn()===false)throw new RuntimeException('Apply the platform configuration migration first');$stmt=$this->db->prepare('SELECT * FROM coop_platform_configuration_commands WHERE key_hash=?');$stmt->execute([$keyHash]);$prior=$stmt->fetch(PDO::FETCH_ASSOC);
   if($prior){if(!hash_equals($prior['request_hash'],$hash))throw new RuntimeException('Configuration command key conflicts with different changes',409);$result=json_decode($prior['response_json'],true,512,JSON_THROW_ON_ERROR);$this->db->commit();return$result;}
   $result=$operation($actor);$stmt=$this->db->prepare('INSERT INTO coop_platform_configuration_commands(key_hash,request_hash,response_json)VALUES(?,?,?)');$stmt->execute([$keyHash,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);$this->db->commit();return$result;
  }catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
 }
 private function audit(string $actor,string $event,array $metadata):void{$stmt=$this->db->prepare('INSERT INTO coop_platform_configuration_history(platform_actor_id,event_type,redacted_metadata)VALUES(?,?,?)');$stmt->execute([$actor,$event,json_encode($metadata,JSON_THROW_ON_ERROR)]);}
 public function saveBranding(array $principal,int $revision,string $key,array $input):array{
  if(array_diff(array_keys($input),['cooperative_name','primary_color','accent_color','logo_asset_id','favicon_asset_id']))throw new InvalidArgumentException('Unsupported branding fields');
  $name=$this->text($input['cooperative_name']??null,160);$primary=$input['primary_color']??null;$accent=$input['accent_color']??null;foreach([$primary,$accent]as$color)if(!is_string($color)||!preg_match('/\A#[a-fA-F0-9]{6}\z/',$color))throw new InvalidArgumentException('Use six-digit hexadecimal branding colors');
  $normalized=['cooperative_name'=>$name,'primary_color'=>strtolower($primary),'accent_color'=>strtolower($accent)];foreach(['logo','favicon']as$kind){$id=$input[$kind.'_asset_id']??null;if($id!==null&&(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/',$id)))throw new InvalidArgumentException('Invalid branding asset');$normalized[$kind.'_asset_id']=$id;}
  return$this->mutate($principal,$key,['action'=>'branding','revision'=>$revision,'branding'=>$normalized],function($actor)use($revision,$normalized){
   $before=$this->db->query('SELECT * FROM coop_deployment_branding WHERE id=1')->fetch(PDO::FETCH_ASSOC);if((int)$before['revision']!==$revision)throw new RuntimeException('Branding changed; reload before saving',409);
   foreach(['logo','favicon']as$kind)if($normalized[$kind.'_asset_id']!==null){$stmt=$this->db->prepare('SELECT asset_id FROM coop_branding_assets WHERE asset_id=? AND asset_kind=?');$stmt->execute([$normalized[$kind.'_asset_id'],$kind]);if(!$stmt->fetchColumn())throw new InvalidArgumentException('Select an approved branding asset');}
   $stmt=$this->db->prepare('UPDATE coop_deployment_branding SET revision=revision+1,cooperative_name=?,primary_color=?,accent_color=?,logo_asset_id=?,favicon_asset_id=?,updated_by=?,updated_at=NOW() WHERE id=1');$stmt->execute([$normalized['cooperative_name'],$normalized['primary_color'],$normalized['accent_color'],$normalized['logo_asset_id'],$normalized['favicon_asset_id'],$actor]);$this->audit($actor,'branding_saved',['revision'=>$revision+1,'branding'=>$normalized]);return self::branding(array_merge($normalized,['revision'=>$revision+1]));
  });
 }
 public function saveIntegration(array $principal,string $provider,int $revision,string $key,bool $enabled,array $config,array $changes):array{
  $config=$this->config($provider,$config);$names=['smtp'=>['password'],'paystack'=>['secret_key'],'sms'=>['api_key']][$provider];if(array_diff(array_keys($changes),$names))throw new InvalidArgumentException('Unsupported credential fields');$normalized=[];
  foreach($names as$name){$change=$changes[$name]??['action'=>'retain'];if(!is_array($change)||!in_array($change['action']??null,['retain','replace','clear'],true)||array_diff(array_keys($change),['action','value']))throw new InvalidArgumentException('Choose retain, replace or clear for credentials');$action=$change['action'];$normalized[$name]=['action'=>$action];if($action==='replace'){$value=$change['value']??null;if(!is_string($value)||trim($value)===''||strlen($value)>4096||preg_match('/[\x00-\x1f\x7f]/',$value))throw new InvalidArgumentException('Provide a valid replacement credential');if($provider==='paystack'&&!preg_match('/\Ask_'.preg_quote($config['mode'],'/').'_[A-Za-z0-9]+\z/',$value))throw new InvalidArgumentException('Paystack secret key must match the selected mode');$normalized[$name]['value']=$value;}}
  return$this->mutate($principal,$key,['action'=>'integration','provider'=>$provider,'revision'=>$revision,'enabled'=>$enabled,'config'=>$config,'changes'=>$normalized],function($actor)use($provider,$revision,$enabled,$config,$normalized){
   $stmt=$this->db->prepare('SELECT * FROM coop_integration_configuration WHERE provider=?');$stmt->execute([$provider]);$before=$stmt->fetch(PDO::FETCH_ASSOC);if((int)($before['revision']??0)!==$revision)throw new RuntimeException('Integration settings changed; reload before saving',409);$secrets=$before?json_decode($before['encrypted_secrets'],true,512,JSON_THROW_ON_ERROR):[];
   foreach($normalized as$name=>$change){if($change['action']==='clear')unset($secrets[$name]);if($change['action']==='replace')$secrets[$name]=$this->cipher->seal($change['value'],'integration:'.$provider.':'.$name);}
   if($enabled){foreach(array_keys($normalized)as$name)if(!isset($secrets[$name]))throw new InvalidArgumentException('Enabled connections require their credential');if($provider==='paystack'&&!preg_match('/\Ask_'.preg_quote($config['mode'],'/').'_[A-Za-z0-9]+\z/',$this->cipher->open($secrets['secret_key'],'integration:paystack:secret_key')))throw new InvalidArgumentException('Retained Paystack key does not match the selected mode');}
   $stmt=$this->db->prepare('INSERT INTO coop_integration_configuration(provider,revision,enabled,config_json,encrypted_secrets,updated_by)VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),enabled=VALUES(enabled),config_json=VALUES(config_json),encrypted_secrets=VALUES(encrypted_secrets),updated_by=VALUES(updated_by),updated_at=NOW()');$stmt->execute([$provider,$revision+1,(int)$enabled,json_encode($config,JSON_THROW_ON_ERROR),json_encode($secrets,JSON_THROW_ON_ERROR),$actor]);$result=$this->masked($provider,['revision'=>$revision+1,'enabled'=>$enabled,'config_json'=>json_encode($config),'encrypted_secrets'=>json_encode($secrets)]);$this->audit($actor,'integration_saved',['provider'=>$provider,'revision'=>$revision+1,'enabled'=>$enabled,'credential_actions'=>array_map(fn($change)=>$change['action'],$normalized)]);return$result;
  },true);
 }
}
