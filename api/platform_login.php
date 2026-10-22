<?php
require_once __DIR__.'/../includes/auracle_platform_auth.php';require_once __DIR__.'/../includes/admin_access_helper.php';
ini_set('display_errors','0');header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');throw new RuntimeException('Use POST to sign in',405);}
 if(($error=findAdminMutationOriginError($_SERVER))!==null)throw new RuntimeException($error,403);
 $host=parse_url('http://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST);if((empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')&&!in_array($host,['localhost','127.0.0.1','[::1]'],true))throw new RuntimeException('Platform login requires HTTPS',403);
 $body=file_get_contents('php://input',false,null,0,8193);if($body===false||strlen($body)>8192)throw new InvalidArgumentException('Invalid login request');$input=json_decode($body,true);if(!is_array($input))$input=$_POST;
 initAuraclePlatformSession();$principal=(new AuraclePlatformAuth(auraclePlatformDatabase()))->login($input['username']??null,$input['password']??null,$_SERVER['REMOTE_ADDR']??'');
 session_regenerate_id(true);$_SESSION=['AURACLE_PLATFORM_ADMIN_ID'=>$principal['id'],'AURACLE_PLATFORM_AUTH_VERSION'=>$principal['auth_version'],'AURACLE_PLATFORM_DEPLOYMENT'=>auraclePlatformDeploymentId()];echo json_encode(['success'=>true,'redirect'=>'platform_settings.php']);
}catch(Throwable$error){$status=(int)$error->getCode();if(!in_array($status,[401,403,405,429],true))$status=$error instanceof InvalidArgumentException?400:500;http_response_code($status);echo json_encode(['success'=>false,'message'=>$status===500?'Platform sign-in is unavailable; ask the deployment administrator to check provisioning.':$error->getMessage()]);}
