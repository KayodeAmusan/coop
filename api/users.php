<?php
if(session_status()===PHP_SESSION_NONE)session_start();
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
require_once __DIR__.'/../libs/services/CooperativeAccessService.php';
try{
    $actor=CooperativeAccessService::staffId($_SESSION['SESS_MEMBER_ID']??null);
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')throw new RuntimeException('Use the audited cooperative access command workflow for user changes',409);
    foreach(['page','records_per_page']as$f)if(isset($_GET[$f])&&(!is_string($_GET[$f])||!preg_match('/\A[1-9][0-9]{0,4}\z/',$_GET[$f])))throw new InvalidArgumentException('Valid bounded pagination required',400);
    require_once __DIR__.'/../config/EnvConfig.php';$c=EnvConfig::getDatabaseConfig();
    $conn=new PDO('mysql:host='.$c['host'].';dbname='.$c['name'].';charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $result=(new CooperativeAccessService($conn))->userDirectory($actor,(int)($_GET['page']??1),(int)($_GET['records_per_page']??25));
    echo json_encode(array_merge(['success'=>true],$result),JSON_THROW_ON_ERROR);
}catch(Throwable$e){$status=$e instanceof PDOException?500:(int)$e->getCode();if(!in_array($status,[400,403,409],true))$status=500;if($status===500)error_log('Cooperative user directory failed');http_response_code($status);echo json_encode(['success'=>false,'message'=>$status===500?'Unable to load cooperative users.':$e->getMessage()]);}
