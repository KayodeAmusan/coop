<?php
require_once __DIR__ . '/guarantor_evidence_service.php';
require_once __DIR__ . '/guarantor_evidence_config.php';

function handleGuarantorEvidenceRequest(bool $staff): void {
    ini_set('display_errors','0');
    header('Cache-Control: private, no-store, max-age=0');header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');
    try {
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        if($staff){
            require_once __DIR__.'/session_helper.php';initSession();require_once __DIR__.'/admin_access_helper.php';
            if(($error=findAdminAccessError($_SESSION))!==null)throw new RuntimeException($error,403);
            if(($error=findAdminMutationOriginError($_SERVER))!==null)throw new RuntimeException($error,403);
            $id=$_SESSION['SESS_MEMBER_ID'];
            if((!is_int($id)&&!is_string($id))||!preg_match('/\A[1-9][0-9]*\z/',(string)$id)||(int)$id>2147483647)throw new RuntimeException('A valid administrator login is required',403);
            $actor=['type'=>'staff','id'=>(string)$id];
        }else{
            $headers=array_change_key_case(getallheaders(),CASE_LOWER);$authorization=$headers['authorization']??'';
            if(!is_string($authorization)||!preg_match('/\ABearer\s+(\S+)\z/i',$authorization,$matches))throw new RuntimeException('A member login is required',401);
            require_once __DIR__.'/../auth_api/utils/JWTHandler.php';$claims=(new JWTHandler())->validateToken($matches[1]);
            if(!$claims)throw new RuntimeException('Invalid member login',401);
            $actor=['type'=>'member','id'=>authenticatedLoanMember($claims)];
        }
        if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');throw new RuntimeException('Use GET to view or POST to change evidence',405);}
        $input=$method==='GET'?$_GET:$_POST;
        if($method==='POST'&&str_contains(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')){
            $raw=file_get_contents('php://input',false,null,0,262145);
            if($raw===false||strlen($raw)>262144)throw new InvalidArgumentException('Evidence request body is too large',400);
            $input=json_decode($raw,true);
            if(!is_array($input))throw new InvalidArgumentException('Invalid evidence request',400);
        }
        $action=$input['action']??($method==='POST'?'upload':'list');
        if(!is_string($action)||!in_array($action,['requests','list','upload','download','review','rescan'],true))throw new InvalidArgumentException('Invalid evidence action',400);
        if(($method==='GET'&&!in_array($action,['requests','list','download'],true))||($method==='POST'&&in_array($action,['requests','list','download'],true)))throw new RuntimeException('Use the documented method for this evidence action',405);
        if(!$staff&&in_array($action,['review','rescan'],true))throw new RuntimeException('Staff review is required',403);
        if($action==='requests'&&((!is_string($input['loan_request_id']??null)&&!is_int($input['loan_request_id']??null))||!preg_match('/\A[1-9][0-9]*\z/',(string)$input['loan_request_id'])||(int)$input['loan_request_id']>2147483647))throw new InvalidArgumentException('Select a valid loan request',400);
        $invitationId=$input['guarantor_request_id']??null;$documentId=$input['document_id']??null;
        if(in_array($action,['list','upload'],true)){
            if((!is_string($invitationId)&&!is_int($invitationId))||!preg_match('/\A[1-9][0-9]*\z/',(string)$invitationId)||(int)$invitationId>2147483647)throw new InvalidArgumentException('Select a valid guarantor invitation',400);
        }elseif($action!=='requests'&&(!is_string($documentId)||!preg_match('/\A[a-f0-9]{32}\z/',$documentId)))throw new InvalidArgumentException('Select a valid evidence document',400);
        if($action==='upload'&&(!is_string($input['evidence_kind']??null)||!in_array($input['evidence_kind'],['acknowledgement','approval'],true)||!is_string($input['upload_key']??null)||!preg_match('/\A[A-Za-z0-9_-]{16,128}\z/',$input['upload_key'])||!isset($_FILES['evidence'])||!is_array($_FILES['evidence'])))throw new InvalidArgumentException('Select a PDF, JPEG or PNG evidence upload and its category',400);
        if($action==='review'&&(!is_string($input['decision']??null)||!is_string($input['notes']??'')))throw new InvalidArgumentException('Select a review decision and reason',400);
        $limit=20;$beforeId=null;
        if($action==='list'){
            foreach(['limit','before_id']as$field){if(isset($input[$field])&&((!is_string($input[$field])&&!is_int($input[$field]))||!preg_match('/\A[1-9][0-9]*\z/',(string)$input[$field])||(int)$input[$field]>2147483647))throw new InvalidArgumentException('Invalid evidence page',400);}
            $limit=isset($input['limit'])?(int)$input['limit']:20;$beforeId=isset($input['before_id'])?(int)$input['before_id']:null;
            if($limit>50)throw new InvalidArgumentException('Evidence page limit must not exceed 50',400);
        }
        require_once __DIR__.'/../auth_api/config/Database.php';$db=(new Database())->getConnection();
        $authorize=function(string $operation,array $who,array $context)use($db,$staff):bool{
            if($who['type']==='staff')return $staff;
            if(!in_array($operation,['upload','view','download'],true))return false;
            $member=$db->prepare("SELECT Status FROM tblemployees WHERE CoopID=?");$member->execute([$who['id']]);
            if($member->fetchColumn()!=='Active')return false;
            return $who['id']===(string)$context['loan']['requester_coop_id']||$who['id']===(string)$context['invitation']['guarantor_coop_id'];
        };
        $service=new GuarantorEvidenceService($db,configuredGuarantorEvidenceStorage(),$authorize);
        switch($action){
            case 'requests':$data=$service->requestsForLoan($input['loan_request_id'],$actor);break;
            case 'upload':$data=$service->upload($invitationId,$input['evidence_kind'],$_FILES['evidence'],$actor,$input['upload_key']);break;
            case 'list':$data=$service->listForInvitation($invitationId,$actor,$limit,$beforeId);break;
            case 'download':$service->download($documentId,$actor);return;
            case 'review':$data=$service->reviewSupportingEvidence($documentId,$input['decision'],$input['notes']??'',$actor);break;
            case 'rescan':$data=$service->rescan($documentId,$actor);break;
        }
        echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR);
    }catch(Throwable $error){
        $code=(int)$error->getCode();if(!in_array($code,[400,401,403,404,405,409],true))$code=$error instanceof InvalidArgumentException?400:500;
        http_response_code($code);
        $message=$code===500?'Evidence request could not be confirmed. Reload evidence history before retrying.':$error->getMessage();
        error_log('Guarantor evidence request failed: '.get_class($error));
        echo json_encode(['success'=>false,'message'=>$message],JSON_THROW_ON_ERROR);
    }
}
