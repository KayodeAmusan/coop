<?php
require_once __DIR__.'/deployment_settings_service.php';
function deploymentSettingsDatabase(){require_once __DIR__.'/../auth_api/config/Database.php';return(new Database())->getConnection();}
function deploymentPublicBranding(?PDO $db=null):array{
 static$cache=[];$cacheKey=$db?'pdo:'.spl_object_id($db):'default';if(isset($cache[$cacheKey]))return$cache[$cacheKey];
 $empty=['configured'=>false,'revision'=>0,'cooperative_name'=>null,'primary_color'=>null,'accent_color'=>null,'logo_asset_id'=>null,'favicon_asset_id'=>null,'logo_url'=>null,'favicon_url'=>null];
 try{$db=$db??deploymentSettingsDatabase();$row=$db->query('SELECT revision,cooperative_name,primary_color,accent_color,logo_asset_id,favicon_asset_id FROM coop_deployment_branding WHERE id=1')->fetch(PDO::FETCH_ASSOC);return$cache[$cacheKey]=$row?DeploymentSettingsService::branding($row):$empty;}catch(Throwable$error){return$cache[$cacheKey]=$empty;}
}
/** Server/worker use only. Do not serialize this return value to an API response or log. */
function deploymentIntegrationRuntime($db,string $provider,?DeploymentSecretCipher $cipher=null):?array{
 if(!in_array($provider,['smtp','paystack','sms'],true))throw new InvalidArgumentException('Unsupported integration');
 try{$stmt=$db->prepare('SELECT enabled,config_json,encrypted_secrets FROM coop_integration_configuration WHERE provider=?');$stmt->execute([$provider]);$row=$stmt->fetch(PDO::FETCH_ASSOC);}catch(PDOException$error){if($error->getCode()==='42S02')return null;throw$error;}
 if(!$row)return null;$result=['enabled'=>(bool)$row['enabled'],'config'=>json_decode($row['config_json'],true,512,JSON_THROW_ON_ERROR),'secrets'=>[]];
 $encrypted=json_decode($row['encrypted_secrets'],true,512,JSON_THROW_ON_ERROR);if($encrypted){$cipher=$cipher??deploymentSettingsCipher();foreach($encrypted as$field=>$envelope)$result['secrets'][$field]=$cipher->open($envelope,'integration:'.$provider.':'.$field);}
 return$result;
}
function deploymentMailConfiguration($db=null):array{
 $db=$db??deploymentSettingsDatabase();$runtime=deploymentIntegrationRuntime($db,'smtp');
 if($runtime!==null){$config=$runtime['config'];return['enabled'=>$runtime['enabled'],'host'=>$config['host'],'port'=>$config['port'],'username'=>$config['username'],'password'=>$runtime['secrets']['password']??'','encryption'=>$config['encryption']==='starttls'?'tls':'ssl','from_address'=>$config['from_email'],'from_name'=>$config['from_name']];}
 require_once __DIR__.'/../config/EnvConfig.php';
 return['enabled'=>EnvConfig::get('SMTP_HOST','')!=='','host'=>EnvConfig::get('SMTP_HOST',''),'port'=>(int)EnvConfig::get('SMTP_PORT',587),'username'=>EnvConfig::get('SMTP_USERNAME',''),'password'=>EnvConfig::get('SMTP_PASSWORD',''),'encryption'=>EnvConfig::get('SMTP_ENCRYPTION','tls'),'from_address'=>EnvConfig::get('SMTP_FROM',''),'from_name'=>EnvConfig::get('SMTP_FROM_NAME','')];
}
function deploymentSmsConfiguration($db=null):array{
 $db=$db??deploymentSettingsDatabase();$runtime=deploymentIntegrationRuntime($db,'sms');return['enabled'=>false,'adapter_implemented'=>false,'configuration_present'=>$runtime!==null,'sender'=>$runtime['config']['sender_id']??null,'apiKey'=>null,'endpoint'=>null,'reason'=>'adapter_not_implemented'];
}

/** Configure without opening a connection; disabled integrations must never send. */
function configureDeploymentMailer(\PHPMailer\PHPMailer\PHPMailer $mailer,$db=null):void{
 $config=deploymentMailConfiguration($db);
 if(!$config['enabled'])throw new RuntimeException('Email delivery is not configured or is disabled');
 if(!filter_var($config['from_address'],FILTER_VALIDATE_EMAIL)||$config['host']==='')throw new RuntimeException('Email configuration is incomplete');
 $mailer->isSMTP();$mailer->Host=$config['host'];$mailer->Port=$config['port'];
 $mailer->SMTPAuth=$config['username']!=='';$mailer->Username=$config['username'];$mailer->Password=$config['password'];
 $mailer->SMTPSecure=$config['encryption'];$mailer->CharSet='UTF-8';$mailer->Timeout=30;$mailer->SMTPDebug=0;
 $mailer->setFrom($config['from_address'],$config['from_name']);
}
