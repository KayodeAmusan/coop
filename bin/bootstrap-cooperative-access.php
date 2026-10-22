<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../libs/services/CooperativeAccessService.php';
if(count($argv)!==4||$argv[1]!=='--grant-initial-editor'){
    fwrite(STDERR,"Usage: php bin/bootstrap-cooperative-access.php --grant-initial-editor ACTIVE_STAFF_ID STABLE_COMMAND_KEY\n");exit(2);
}
try{
    $staffId=CooperativeAccessService::staffId($argv[2]);
    require_once __DIR__.'/../Connections/coop.php';
    $result=(new CooperativeAccessService($conn))->bootstrapForDeployment($staffId,$argv[3]);
    fwrite(STDOUT,json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR)."\n");
}catch(Throwable$e){fwrite(STDERR,($e instanceof PDOException?'Unable to configure cooperative access; verify deployment migrations and database configuration.':$e->getMessage())."\n");exit(1);}
