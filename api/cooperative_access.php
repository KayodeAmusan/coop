<?php
if(session_status()===PHP_SESSION_NONE)session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__.'/../includes/admin_access_helper.php';
require_once __DIR__.'/../libs/services/CooperativeAccessService.php';
try {
    $actor=CooperativeAccessService::staffId($_SESSION['SESS_MEMBER_ID']??null);
    $method=$_SERVER['REQUEST_METHOD']??'';
    if(!in_array($method,['GET','POST'],true))throw new RuntimeException('Method not allowed',405);
    if($method==='POST'&&($originError=findAdminMutationOriginError($_SERVER))!==null)throw new RuntimeException($originError,403);
    $input=null;
    if($method==='POST') {
        $raw=file_get_contents('php://input',false,null,0,65537);
        if($raw===false||strlen($raw)>65536)throw new InvalidArgumentException('Access command too large',413);
        try{$input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException$e){throw new InvalidArgumentException('A valid JSON command is required',400);}
        if(!is_array($input)||array_is_list($input)||array_diff(array_keys($input),['action','payload','idempotency_key'])||!isset($input['action'],$input['payload'],$input['idempotency_key'])||!is_string($input['action'])||!is_array($input['payload'])||!is_string($input['idempotency_key']))throw new InvalidArgumentException('Action, payload and submission reference required',400);
    }
    require_once __DIR__.'/../config/EnvConfig.php';
    $config=EnvConfig::getDatabaseConfig();
    $conn=new PDO('mysql:host='.$config['host'].';dbname='.$config['name'].';charset=utf8mb4',$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $service=new CooperativeAccessService($conn);
    // A legacy Admin session is never an implicit role-editor grant.
    $service->requirePermission($actor,$method==='POST'?CooperativeAccessService::permissionForAction($input['action']):'access.roles.manage');
    if($method==='POST')$result=$service->runCommand($actor,$input['action'],$input['payload'],$input['idempotency_key']);
    else {
        foreach(['page','limit']as$f)if(isset($_GET[$f])&&(!is_string($_GET[$f])||!preg_match('/\A[1-9][0-9]{0,4}\z/',$_GET[$f])))throw new InvalidArgumentException('Valid pagination required',400);
        $result=$service->catalog($actor,(int)($_GET['page']??1),(int)($_GET['limit']??25));
    }
    echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR);
} catch(Throwable$e) {
    $status=$e instanceof PDOException?500:(int)$e->getCode();
    if(!in_array($status,[400,403,404,405,409,413],true))$status=500;
    if($status===500)error_log('Cooperative access administration failed');
    http_response_code($status);
    echo json_encode(['success'=>false,'message'=>$status===500?'Unable to complete access administration. Retry with the same submission reference.':$e->getMessage()]);
}
