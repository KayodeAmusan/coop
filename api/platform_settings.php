<?php
require_once __DIR__.'/../includes/auracle_platform_auth.php';require_once __DIR__.'/../includes/admin_access_helper.php';require_once __DIR__.'/../includes/deployment_settings_helper.php';
ini_set('display_errors','0');header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 $method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');throw new RuntimeException('Use GET or POST for platform settings',405);}
 if(($error=findAdminMutationOriginError($_SERVER))!==null)throw new RuntimeException($error,403);
 $principal=requireAuraclePlatformPrincipal();$db=deploymentSettingsDatabase();$authorize=fn($who)=>($who['id']??null)===$principal['id'];
 if($method==='GET'){$data=(new DeploymentSettingsService($db,null,$authorize))->settings($principal);}else{
  $raw=file_get_contents('php://input',false,null,0,262145);if($raw===false||strlen($raw)>262144)throw new InvalidArgumentException('Settings request is too large');$input=json_decode($raw,true);if(!is_array($input)||!is_string($input['action']??null))throw new InvalidArgumentException('Invalid settings request');
  $action=$input['action'];if(!in_array($action,['save_branding','save_integration','test_connection'],true))throw new InvalidArgumentException('Unsupported platform settings action');
  if($action==='test_connection'){require_once __DIR__.'/../includes/deployment_connection_tester.php';if(!is_string($input['provider']??null))throw new InvalidArgumentException('Select a configured connection');$data=(new DeploymentConnectionTester())->test($input['provider'],deploymentIntegrationRuntime($db,$input['provider']));}
  else{
   if(!is_int($input['expected_revision']??null)||$input['expected_revision']<0||!is_string($input['command_key']??null))throw new InvalidArgumentException('Expected revision and stable command key are required');
   if($action==='save_branding'){if(!is_array($input['branding']??null))throw new InvalidArgumentException('Provide branding fields');$data=(new DeploymentSettingsService($db,null,$authorize))->saveBranding($principal,$input['expected_revision'],$input['command_key'],$input['branding']);}
   else{if(!is_string($input['provider']??null)||!is_bool($input['enabled']??null)||!is_array($input['config']??null)||!is_array($input['secrets']??null))throw new InvalidArgumentException('Provide connection configuration and explicit secret actions');$data=(new DeploymentSettingsService($db,deploymentSettingsCipher(),$authorize))->saveIntegration($principal,$input['provider'],$input['expected_revision'],$input['command_key'],$input['enabled'],$input['config'],$input['secrets']);}
  }
 }
 echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR);
}catch(Throwable$error){$status=(int)$error->getCode();if(!in_array($status,[403,405,409],true))$status=$error instanceof InvalidArgumentException?400:500;http_response_code($status);echo json_encode(['success'=>false,'message'=>$status===500?'Platform settings could not be confirmed. Reload saved settings before retrying.':$error->getMessage()]);}
