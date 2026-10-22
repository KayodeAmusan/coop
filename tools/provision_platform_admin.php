<?php
/** Explicit deployment-host action only. Password arrives on stdin, never argv or source control. */
if(PHP_SAPI!=='cli')exit(2);
$options=getopt('',['username:','reset']);$username=$options['username']??null;
if(!is_string($username)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.@-]{2,189}\z/',$username)){fwrite(STDERR,"Provide --username explicitly. Read the deployment guide before provisioning.\n");exit(2);}
$password=rtrim(fgets(STDIN)?:'',"\r\n");if(strlen($password)<12||strlen($password)>(defined('PASSWORD_ARGON2ID')?128:72)){fwrite(STDERR,"Provide a 12–128 byte password through standard input.\n");exit(2);}
require_once __DIR__.'/../includes/auracle_platform_auth.php';$db=auraclePlatformDatabase();$db->beginTransaction();
try{$stmt=$db->prepare('SELECT id FROM auracle_platform_admins WHERE username=? FOR UPDATE');$stmt->execute([strtolower($username)]);$id=$stmt->fetchColumn();
 if($id&&!array_key_exists('reset',$options))throw new RuntimeException('Account exists; use an explicit --reset to change its password and revoke existing sessions');
 $hash=password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT);
 if($id){$stmt=$db->prepare("UPDATE auracle_platform_admins SET password_hash=?,auth_version=auth_version+1,status='active' WHERE id=?");$stmt->execute([$hash,$id]);}else{$stmt=$db->prepare('INSERT INTO auracle_platform_admins(username,password_hash)VALUES(?,?)');$stmt->execute([strtolower($username),$hash]);$id=$db->lastInsertId();}
 $stmt=$db->prepare("INSERT INTO coop_platform_configuration_history(platform_actor_id,event_type,redacted_metadata)VALUES('deployment-cli','platform_account_provisioned',?)");$stmt->execute([json_encode(['platform_id'=>(int)$id,'reset'=>array_key_exists('reset',$options)])]);$db->commit();echo "Auracle platform account provisioned; no cooperative account was elevated.\n";
}catch(Throwable$error){if($db->inTransaction())$db->rollBack();fwrite(STDERR,"Platform account provisioning failed. Check the deployment configuration and explicit reset choice.\n");exit(1);}
