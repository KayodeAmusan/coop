<?php
require_once __DIR__.'/../config/EnvConfig.php';
function auraclePlatformDatabase(){require_once __DIR__.'/../auth_api/config/Database.php';return(new Database())->getConnection();}
function auraclePlatformDeploymentId():string{return hash('sha256',realpath(__DIR__.'/..').'|'.EnvConfig::get('DB_NAME',''));}
function initAuraclePlatformSession():void{
 $secure=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';$host=parse_url('http://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST);$local=in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)&&in_array($host,['localhost','127.0.0.1','[::1]'],true);
 if(!$secure&&!$local)throw new RuntimeException('Platform authentication requires HTTPS',403);

 if(session_status()===PHP_SESSION_ACTIVE){if(session_name()!=='AURACLE_PLATFORM_SESSION')throw new RuntimeException('Platform authentication requires its separate login',403);return;}
 ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');session_name('AURACLE_PLATFORM_SESSION');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Strict']);session_start();
}
function requireAuraclePlatformPrincipal():array{
 initAuraclePlatformSession();$id=$_SESSION['AURACLE_PLATFORM_ADMIN_ID']??null;$version=$_SESSION['AURACLE_PLATFORM_AUTH_VERSION']??null;
 if(!is_int($id)||$id<1||!is_int($version)||($_SESSION['AURACLE_PLATFORM_DEPLOYMENT']??null)!==auraclePlatformDeploymentId())throw new RuntimeException('Sign in with your Auracle platform account',403);
 $db=auraclePlatformDatabase();$stmt=$db->prepare("SELECT id,username,auth_version FROM auracle_platform_admins WHERE id=? AND status='active'");$stmt->execute([$id]);$principal=$stmt->fetch(PDO::FETCH_ASSOC);
 if(!$principal||(int)$principal['auth_version']!==$version){unset($_SESSION['AURACLE_PLATFORM_ADMIN_ID'],$_SESSION['AURACLE_PLATFORM_AUTH_VERSION']);throw new RuntimeException('Platform authentication expired; sign in again',403);}
 return['id'=>(int)$principal['id'],'username'=>$principal['username']];
}
class AuraclePlatformAuth{
 private $db;
 public function __construct($db){$this->db=$db;}
 public function login($username,$password,string $ip):array{
  if(!is_string($username)||!is_string($password)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.@-]{2,189}\z/',$username)||strlen($password)>1024)throw new RuntimeException('Invalid platform login',401);
  $username=strtolower($username);$limits=[hash('sha256','username:'.$username)=>20,hash('sha256','ip:'.$ip)=>10];ksort($limits);
  if($this->db->inTransaction())throw new LogicException('Platform login owns its transaction');$this->db->beginTransaction();
  try{
   foreach($limits as$key=>$maximum){$stmt=$this->db->prepare('INSERT INTO auracle_platform_login_limits(limit_key,failed_attempts,window_started)VALUES(?,0,NOW()) ON DUPLICATE KEY UPDATE limit_key=limit_key');$stmt->execute([$key]);$stmt=$this->db->prepare('SELECT failed_attempts,window_started,locked_until,(locked_until > NOW()) AS is_locked,(window_started <= DATE_SUB(NOW(),INTERVAL 15 MINUTE)) AS expired_window FROM auracle_platform_login_limits WHERE limit_key=? FOR UPDATE');$stmt->execute([$key]);$limit=$stmt->fetch(PDO::FETCH_ASSOC);
    if((int)$limit['is_locked']===1)throw new RuntimeException('Too many platform login attempts; try again later',429);
    if((int)$limit['expired_window']===1){$stmt=$this->db->prepare('UPDATE auracle_platform_login_limits SET failed_attempts=0,window_started=NOW(),locked_until=NULL WHERE limit_key=?');$stmt->execute([$key]);}
   }
   $stmt=$this->db->prepare("SELECT id,username,password_hash,auth_version,status FROM auracle_platform_admins WHERE username=? FOR UPDATE");$stmt->execute([$username]);$user=$stmt->fetch(PDO::FETCH_ASSOC);
   // This hash is a timing-only non-account value; it never provisions or authenticates an identity.
   $hash=$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
   $valid=password_verify($password,$hash)&&$user&&$user['status']==='active';
   if(!$valid){foreach($limits as$key=>$maximum){$stmt=$this->db->prepare('UPDATE auracle_platform_login_limits SET failed_attempts=failed_attempts+1,locked_until=CASE WHEN failed_attempts>=? THEN DATE_ADD(NOW(),INTERVAL 15 MINUTE) ELSE locked_until END WHERE limit_key=?');$stmt->execute([$maximum,$key]);}$this->db->commit();throw new RuntimeException('Invalid platform login',401);}
   $stmt=$this->db->prepare('UPDATE auracle_platform_admins SET last_login_at=NOW() WHERE id=?');$stmt->execute([$user['id']]);$this->db->commit();return['id'=>(int)$user['id'],'username'=>$user['username'],'auth_version'=>(int)$user['auth_version']];
  }catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
 }
}
