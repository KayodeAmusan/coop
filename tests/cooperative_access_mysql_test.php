<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../libs/services/CooperativeAccessService.php';
if(getenv('COOP_ACCESS_MYSQL_TEST')!=='1'){echo "SKIP isolated cooperative access tests\n";exit;}
function accessEditorRole():int{return(int)accessDb()->query("SELECT id FROM coop_access_roles WHERE role_code='cooperative_access_administrator'")->fetchColumn();}
function accessDb():PDO{return new PDO('mysql:host=127.0.0.1;port=13307;dbname=coop_test_access;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
if(($argv[1]??'')==='worker'){echo json_encode((new CooperativeAccessService(accessDb()))->runCommand(1,'create_role',['role_code'=>'race','display_name'=>'Race'], 'same-race'));exit;}
$server=new PDO('mysql:host=127.0.0.1;port=13307','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$server->exec('CREATE DATABASE IF NOT EXISTS coop_test_access');$db=accessDb();
foreach(['coop_access_role_permissions','coop_access_staff_roles','coop_access_roles','coop_access_commands','coop_access_control','tblusers','coop_audit_trail']as$t)$db->exec('DROP TABLE IF EXISTS '.$t);
$db->exec("CREATE TABLE tblusers(user_id INT PRIMARY KEY,AdminType VARCHAR(40),Status VARCHAR(20),CompleteName VARCHAR(150),Username VARCHAR(80),Email VARCHAR(254),UPassword VARCHAR(255),CreatedDate DATETIME) ENGINE=InnoDB");
$db->exec("INSERT INTO tblusers(user_id,AdminType,Status) VALUES(1,'Admin','Active'),(2,'Finance','Active'),(3,'SuperAdmin','Inactive'),(4,'User','Active')");
function accessSql(PDO$db,string$sql):void{$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,true);$s=$db->query($sql);do{if($s->columnCount())$s->fetchAll();}while($s->nextRowset());$s->closeCursor();$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);}
foreach([1,2]as$i)accessSql($db,file_get_contents(__DIR__.'/../database/migrations/20261021_cooperative_rbac.sql'));
$schema=file_get_contents(__DIR__.'/../SETUP_FULL_ACCOUNTING_SYSTEM.sql');preg_match('/CREATE TABLE IF NOT EXISTS coop_audit_trail\s*\(.*?\) ENGINE=InnoDB[^;]*;/s',$schema,$m);$db->exec($m[0]);$s=new CooperativeAccessService($db);
test('empty schema grants no Admin, Finance or platform permission',function()use($s){foreach([1,2,3,4]as$id)assertSameValue(false,$s->hasPermission($id,'access.roles.manage'));assertSameValue(false,$s->hasPermission(1,'platform.settings.manage'));assertSameValue(false,$s->hasPermission([], 'reports.read'));});
test('operator bootstrap requires explicit active staff, audit and one-time receipt',function()use($db,$s){
    try{$s->bootstrapForDeployment(3,'inactive');throw new Exception('Inactive bootstrap accepted');}catch(RuntimeException$e){assertSameValue(404,$e->getCode());}
    $db->exec('RENAME TABLE coop_audit_trail TO access_audit_unavailable');
    try{$s->bootstrapForDeployment(1,'initial-editor');throw new Exception('Unaudited bootstrap');}catch(PDOException$e){}finally{$db->exec('RENAME TABLE access_audit_unavailable TO coop_audit_trail');}
    assertSameValue(0,(int)$db->query('SELECT COUNT(*) FROM coop_access_roles')->fetchColumn());
    $result=$s->bootstrapForDeployment(1,'initial-editor');assertSameValue('access.roles.manage',$result['permission']);assertSameValue(false,$result['replayed']);assertSameValue(true,$s->bootstrapForDeployment(1,'initial-editor')['replayed']);
    try{$s->bootstrapForDeployment(4,'second-bootstrap');throw new Exception('Second bootstrap accepted');}catch(RuntimeException$e){assertSameValue(409,$e->getCode());}
    assertSameValue(false,$s->hasPermission(1,'access.users.manage'));assertSameValue(false,$s->hasPermission(1,'loans.approve'));
});
test('live assignments grant only catalog capability and active staff',function()use($db,$s){assertSameValue(true,$s->hasPermission(1,'access.roles.manage'));assertSameValue(false,$s->hasPermission(1,'loans.approve'));$db->exec('INSERT INTO coop_access_staff_roles VALUES(3,'.accessEditorRole().')');assertSameValue(false,$s->hasPermission(3,'access.roles.manage'));$db->exec('DELETE FROM coop_access_staff_roles WHERE staff_user_id=3');});
test('idempotent role create returns original result once and mismatches conflict',function()use($db,$s){$p=['role_code'=>'custom','display_name'=>'Custom'];$a=$s->runCommand(1,'create_role',$p,'create');$b=$s->runCommand(1,'create_role',array_reverse($p,true),'create');assertSameValue($a['role_id'],$b['role_id']);assertSameValue(true,$b['replayed']);assertSameValue(2,(int)$db->query('SELECT COUNT(*) FROM coop_audit_trail')->fetchColumn());try{$s->runCommand(1,'create_role',['role_code'=>'changed','display_name'=>'Custom'],'create');throw new Exception('Changed key accepted');}catch(RuntimeException$e){assertSameValue(409,$e->getCode());}});
test('unknown permissions and injected actor are rejected without writes',function()use($db,$s){$n=$db->query('SELECT COUNT(*) FROM coop_access_commands')->fetchColumn();foreach([['role_id'=>accessEditorRole(),'permissions'=>['platform.settings.manage']],['role_id'=>accessEditorRole(),'permissions'=>[],'actor'=>1]]as$p){try{$s->runCommand(1,'set_permissions',$p,'bad');throw new Exception('Bad grant accepted');}catch(InvalidArgumentException$e){assertSameValue(400,$e->getCode());}}assertSameValue($n,$db->query('SELECT COUNT(*) FROM coop_access_commands')->fetchColumn());});
test('last active editor cannot revoke own authority through role or membership edits',function()use($s){foreach([['set_permissions',['role_id'=>accessEditorRole(),'permissions'=>[]]],['set_member_roles',['staff_user_id'=>1,'role_ids'=>[]]]]as[$a,$p]){try{$s->runCommand(1,$a,$p,'last-'.$a);throw new Exception('Last editor removed');}catch(RuntimeException$e){assertSameValue(409,$e->getCode());}assertSameValue(true,$s->hasPermission(1,'access.roles.manage'));}});
test('configured grants and member role replacement are canonical and live',function()use($db,$s){$id=(int)$db->query("SELECT id FROM coop_access_roles WHERE role_code='custom'")->fetchColumn();$s->runCommand(1,'set_permissions',['role_id'=>$id,'permissions'=>['reports.read','loans.approve']],'grant');assertSameValue(true,$s->runCommand(1,'set_permissions',['permissions'=>['loans.approve','reports.read'],'role_id'=>$id],'grant')['replayed']);$s->runCommand(1,'set_member_roles',['staff_user_id'=>2,'role_ids'=>[$id]],'assign');assertSameValue(true,$s->hasPermission(2,'loans.approve'));assertSameValue(false,$s->hasPermission(2,'access.roles.manage'));$db->exec("UPDATE tblusers SET Status='Inactive' WHERE user_id=2");assertSameValue(false,$s->hasPermission(2,'loans.approve'));$db->exec("UPDATE tblusers SET Status='Active' WHERE user_id=2");});
test('unassigned caller cannot administer roles regardless legacy Admin label',function()use($s){try{$s->runCommand(4,'create_role',['role_code'=>'forbidden','display_name'=>'Forbidden'],'not-editor');throw new Exception('Unauthorized create');}catch(RuntimeException$e){assertSameValue(403,$e->getCode());}});
test('audit failure rolls change and receipt back, safe same-key retry',function()use($db,$s){$db->exec('RENAME TABLE coop_audit_trail TO access_audit_unavailable');try{$s->runCommand(1,'create_role',['role_code'=>'audit','display_name'=>'Audit'],'audit');throw new Exception('Missing audit accepted');}catch(PDOException$e){}finally{$db->exec('RENAME TABLE access_audit_unavailable TO coop_audit_trail');}assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM coop_access_roles WHERE role_code='audit'")->fetchColumn());assertSameValue(false,$s->runCommand(1,'create_role',['role_code'=>'audit','display_name'=>'Audit'],'audit')['replayed']);});
test('caller transaction ownership is preserved',function()use($db,$s){$db->beginTransaction();$s->runCommand(1,'create_role',['role_code'=>'outer','display_name'=>'Outer'],'outer');assertSameValue(true,$db->inTransaction());$db->rollBack();assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM coop_access_commands WHERE idempotency_key='outer'")->fetchColumn());assertSameValue(0,(int)$db->query("SELECT COUNT(*) FROM coop_access_roles WHERE role_code='outer'")->fetchColumn());});
test('concurrent same-key commands create one role and one audit',function()use($db){$workers=[];for($i=0;$i<3;$i++){$p=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$p,$pipes];}$ids=[];foreach($workers as[$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);assertSameValue(0,proc_close($p));assertSameValue('',$err);$ids[]=json_decode($out,true,512,JSON_THROW_ON_ERROR)['role_id'];}assertSameValue(1,count(array_unique($ids)));assertSameValue(1,(int)$db->query("SELECT COUNT(*) FROM coop_access_roles WHERE role_code='race'")->fetchColumn());assertSameValue(1,(int)$db->query("SELECT COUNT(*) FROM coop_audit_trail WHERE table_name='coop_access_roles' AND record_id=".$ids[0])->fetchColumn());});
test('user lifecycle requires explicit capability, writes login bcrypt and preserves retry proofs',function()use($db,$s){
    $p=['staff_user_id'=>10,'username'=>'synthetic.staff','complete_name'=>'Synthetic Staff','email'=>'synthetic@example.test','password'=>'Synthetic-Password-123!','status'=>'Active'];
    try{$s->runCommand(1,'create_staff_user',$p,'user-create');throw new Exception('Implicit user management grant');}catch(RuntimeException$e){assertSameValue(403,$e->getCode());}
    $s->runCommand(1,'set_permissions',['role_id'=>accessEditorRole(),'permissions'=>['access.roles.manage','access.users.manage']],'explicit-user-admin');
    $r=$s->runCommand(1,'create_staff_user',$p,'user-create');assertSameValue(10,$r['staff_user_id']);assertSameValue(true,$s->runCommand(1,'create_staff_user',array_reverse($p,true),'user-create')['replayed']);
    $u=$db->query('SELECT AdminType,UPassword FROM tblusers WHERE user_id=10')->fetch(PDO::FETCH_ASSOC);assertSameValue('User',$u['AdminType']);assertSameValue(true,password_verify($p['password'],$u['UPassword']));assertSameValue(false,$s->hasPermission(10,'loans.approve'));
    $changed=$p;$changed['password']='Different-Password-123!';try{$s->runCommand(1,'create_staff_user',$changed,'user-create');throw new Exception('Changed password replay');}catch(RuntimeException$e){assertSameValue(409,$e->getCode());}
    $s->runCommand(1,'reset_staff_password',['staff_user_id'=>10,'password'=>'Replacement-Password-123!'],'reset');assertSameValue(true,$s->runCommand(1,'create_staff_user',$p,'user-create')['replayed']);
    assertSameValue(true,password_verify('Replacement-Password-123!',$db->query('SELECT UPassword FROM tblusers WHERE user_id=10')->fetchColumn()));
    $s->runCommand(1,'update_staff_user',['staff_user_id'=>10,'complete_name'=>'Updated Staff','email'=>'updated@example.test'],'profile');
    $s->runCommand(1,'set_staff_status',['staff_user_id'=>10,'status'=>'Inactive'],'deactivate');assertSameValue('Inactive',$db->query('SELECT Status FROM tblusers WHERE user_id=10')->fetchColumn());
    assertSameValue(false,str_contains(json_encode($db->query('SELECT new_values FROM coop_audit_trail')->fetchAll()),$p['password']));
    assertSameValue(false,str_contains(json_encode($db->query('SELECT request_hash,result_json FROM coop_access_commands')->fetchAll()),$p['password']));
});
test('user lifecycle cannot deactivate last editor or change legacy AdminType',function()use($db,$s){
    try{$s->runCommand(1,'set_staff_status',['staff_user_id'=>1,'status'=>'Inactive'],'last-status');throw new Exception('Last editor disabled');}catch(RuntimeException$e){assertSameValue(409,$e->getCode());}
    try{$s->runCommand(1,'update_staff_user',['staff_user_id'=>10,'complete_name'=>'Escalate','email'=>'test@example.test','AdminType'=>'Admin'],'escalate');throw new Exception('Legacy role injection');}catch(InvalidArgumentException$e){assertSameValue(400,$e->getCode());}
    assertSameValue('Active',$db->query('SELECT Status FROM tblusers WHERE user_id=1')->fetchColumn());
});
test('permission checks use current grant state despite existing repeatable-read snapshot',function()use($db,$s){
    $db->beginTransaction();$db->query('SELECT * FROM coop_access_staff_roles')->fetchAll();
    $other=accessDb();$other->exec('DELETE FROM coop_access_staff_roles WHERE staff_user_id=2');
    assertSameValue(false,$s->hasPermission(2,'loans.approve'));$db->rollBack();
});
test('role API authenticates live grants and ignores privileged session role labels',function()use($db){
    $fixture=sys_get_temp_dir().'/coop-access-api-'.bin2hex(random_bytes(5));
    foreach(['api','includes','Connections','libs/services','config']as$d)mkdir($fixture.'/'.$d,0700,true);
    foreach(['api/cooperative_access.php','includes/admin_access_helper.php','libs/services/CooperativeAccessService.php']as$f)copy(__DIR__.'/../'.$f,$fixture.'/'.$f);
    file_put_contents($fixture.'/config/EnvConfig.php', '<?php class EnvConfig { public static function getDatabaseConfig(){return ["host"=>"127.0.0.1;port=13307","name"=>"coop_test_access","user"=>"root","password"=>""];}}');
    file_put_contents($fixture.'/run.php','<?php session_save_path(__DIR__);session_id("fixture");session_start();$_SESSION=json_decode(getenv("ACCESS_SESSION"),true);$_SERVER["REQUEST_METHOD"]="GET";require __DIR__."/api/cooperative_access.php";');
    try {
        foreach([[[],false],[['SESS_MEMBER_ID'=>4,'role'=>'SuperAdmin'],false],[['SESS_MEMBER_ID'=>1,'role'=>'User'],true]]as[$session,$expected]){
            putenv('ACCESS_SESSION='.json_encode($session));$proc=proc_open([PHP_BINARY,$fixture.'/run.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);assertSameValue(0,proc_close($proc));assertSameValue('',$err);$response=json_decode($out,true,512,JSON_THROW_ON_ERROR);assertSameValue($expected,$response['success']);
            if($expected){assertSameValue(5,$response['data']['total_staff']);assertSameValue(false,str_contains($out,'Password'));}
        }
    } finally {
        foreach(['api/cooperative_access.php','includes/admin_access_helper.php','libs/services/CooperativeAccessService.php','Connections/coop.php','config/EnvConfig.php','run.php','sess_fixture']as$f)if(is_file($fixture.'/'.$f))unlink($fixture.'/'.$f);
        foreach(['api','includes','Connections','libs/services','libs','config']as$d)rmdir($fixture.'/'.$d);rmdir($fixture);putenv('ACCESS_SESSION');
    }
});
test('down migration preserves configured grants and command evidence',function()use($db){accessSql($db,file_get_contents(__DIR__.'/../database/migrations/20261021_cooperative_rbac_down.sql'));assertSameValue(true,(int)$db->query('SELECT COUNT(*) FROM coop_access_commands')->fetchColumn()>0);});
finishTests();
