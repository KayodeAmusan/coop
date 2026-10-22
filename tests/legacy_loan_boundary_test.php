<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../loan/classes/LoanManager.php';
require_once __DIR__.'/../classes/LoanProcessorManager.php';
test('legacy domain mutations fail before any database call',function(){
    $db=new class { function __call($name,$args){throw new RuntimeException('Database reached: '.$name);} };
    $loan=new LoanManager($db,'synthetic');$processor=new LoanProcessorManager($db,'synthetic');
    foreach([$loan->insertLoan([]),$loan->postAccount('',0,0),$processor->updateLoan([]),$processor->deleteLoan(1)] as $result)assertSameValue(false,$result['success']);
});
test('legacy route authorization and mutation rejection precede database loading',function(){
    $root=sys_get_temp_dir().'/coop-legacy-loan-'.bin2hex(random_bytes(6));
    foreach(['loan/api','loan/classes','api','classes','includes','Connections'] as $d)mkdir($root.'/'.$d,0700,true);
    $files=['loan/api/loan.php','api/loan-processor.php','loan/postAccount_.php','includes/admin_access_helper.php'];
    foreach($files as $f)copy(__DIR__.'/../'.$f,$root.'/'.$f);
    foreach(['loan/classes/LoanManager.php','loan/classes/ResponseHandler.php','classes/LoanProcessorManager.php','classes/LoanValidator.php','classes/ResponseHandler.php'] as $f){file_put_contents($root.'/'.$f,'<?php');$files[]=$f;}
    file_put_contents($root.'/Connections/coop.php','<?php echo json_encode(["database_reached"=>true]); exit;');$files[]='Connections/coop.php';
    try {
        foreach(['loan/api/loan.php','api/loan-processor.php','loan/postAccount_.php'] as $route)foreach([['Member','GET',false],['Admin','POST',false],['Admin','GET',$route!=='loan/postAccount_.php']] as [$role,$method,$expected]){
            file_put_contents($root.'/run.php','<?php session_save_path(__DIR__);session_id("fixture");session_start();$_SESSION=["SESS_MEMBER_ID"=>"1","role"=>'.var_export($role,true).'];$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';require __DIR__."/'.$route.'";');
            $p=proc_open([PHP_BINARY,$root.'/run.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);assertSameValue(0,proc_close($p));assertSameValue('',$err);$result=json_decode($out,true,512,JSON_THROW_ON_ERROR);assertSameValue($expected,$result['database_reached']??false);
        }
    } finally {
        foreach(array_merge($files,['run.php','sess_fixture']) as $f)if(is_file($root.'/'.$f))unlink($root.'/'.$f);
        foreach(['loan/api','loan/classes','loan','api','classes','includes','Connections'] as $d)rmdir($root.'/'.$d);rmdir($root);
    }
});
finishTests();
