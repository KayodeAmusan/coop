<?php
/** Cooperative staff permissions. Never accepts a platform identity or a session role as a grant. */
class CooperativeAccessService
{
    public const PERMISSIONS = [
        'access.roles.manage', 'access.users.read', 'access.users.manage',
        'loan.products.manage', 'loans.approve', 'loans.evidence.review', 'loans.evidence.upload',
        'accounting.journals.post', 'accounting.journals.reverse', 'accounting.periods.close',
        'accounting.bank.reconcile', 'audit.read', 'payroll.process', 'payroll.configure', 'members.manage', 'reports.read'
    ];
    private PDO $db;
    private static int $savepoint = 0;
    public function __construct(PDO $db) { $this->db = $db; }

    public static function staffId($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string)$value) || (int)$value > 2147483647) {
            throw new InvalidArgumentException('A valid cooperative staff identity is required', 403);
        }
        return (int)$value;
    }

    public function hasPermission($staffId, string $permission): bool
    {
        if (!in_array($permission, self::PERMISSIONS, true)) return false;
        try { $id = self::staffId($staffId); } catch (InvalidArgumentException $error) { return false; }
        $q = $this->db->prepare("SELECT 1 FROM tblusers u JOIN coop_access_staff_roles sr ON sr.staff_user_id=u.user_id JOIN coop_access_role_permissions rp ON rp.role_id=sr.role_id WHERE u.user_id=? AND BINARY u.Status='Active' AND rp.permission_code=? LIMIT 1 LOCK IN SHARE MODE");
        $q->execute([$id, $permission]); return $q->fetchColumn() !== false;
    }

    public function requirePermission($staffId, string $permission): void
    {
        if (!$this->hasPermission($staffId, $permission)) throw new RuntimeException('Cooperative permission required: '.$permission, 403);
    }

    public function catalog($actor, int $page = 1, int $limit = 25): array
    {
        $this->requirePermission($actor, 'access.roles.manage');
        if($page<1||$page>10000||$limit<1||$limit>100)throw new InvalidArgumentException('Valid bounded pagination required',400);
        $offset=($page-1)*$limit;
        return ['permissions'=>self::PERMISSIONS, 'roles'=>$this->query('SELECT id,role_code,display_name FROM coop_access_roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'grants'=>$this->query('SELECT role_id,permission_code FROM coop_access_role_permissions ORDER BY role_id,permission_code')->fetchAll(PDO::FETCH_ASSOC),
            'staff'=>$this->query('SELECT user_id,CompleteName,Username,Status FROM tblusers ORDER BY user_id LIMIT '.$limit.' OFFSET '.$offset)->fetchAll(PDO::FETCH_ASSOC),
            'memberships'=>$this->query('SELECT sr.staff_user_id,sr.role_id FROM coop_access_staff_roles sr JOIN (SELECT user_id FROM tblusers ORDER BY user_id LIMIT '.$limit.' OFFSET '.$offset.') u ON u.user_id=sr.staff_user_id ORDER BY sr.staff_user_id,sr.role_id')->fetchAll(PDO::FETCH_ASSOC),
            'page'=>$page,'limit'=>$limit,'total_staff'=>(int)$this->query('SELECT COUNT(*) FROM tblusers')->fetchColumn()];
    }

    public function userDirectory($actor,int $page=1,int $limit=25):array
    {
        if(!$this->hasPermission($actor,'access.users.read')&&!$this->hasPermission($actor,'access.users.manage'))throw new RuntimeException('Cooperative user directory permission required',403);
        if($page<1||$page>10000||$limit<1||$limit>100)throw new InvalidArgumentException('Valid bounded pagination required',400);
        $offset=($page-1)*$limit;
        return ['users'=>$this->query('SELECT user_id,Username,CompleteName,Email,AdminType,Status,CreatedDate FROM tblusers ORDER BY user_id LIMIT '.$limit.' OFFSET '.$offset)->fetchAll(PDO::FETCH_ASSOC),'current_page'=>$page,'records_per_page'=>$limit,'total_records'=>(int)$this->query('SELECT COUNT(*) FROM tblusers')->fetchColumn()];
    }

    public function runCommand($actor, string $action, array $payload, string $key): array
    {
        $actor = self::staffId($actor);
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/', $key)) throw new InvalidArgumentException('A stable submission reference is required',400);
        $payload = $this->validate($action,$payload);
        $password=$payload['password']??null;$canonical=$payload;unset($canonical['password']);
        $hash = hash('sha256',json_encode(['action'=>$action,'payload'=>$canonical],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($actor,$action,$payload,$key,$hash,$password){
            $this->query('SELECT id FROM coop_access_control WHERE id=1 FOR UPDATE')->fetchColumn();
            // Recheck after serialization; a concurrently revoked editor cannot issue a stale grant.
            $this->requirePermission($actor,self::permissionForAction($action));
            $proof=$password===null?null:password_hash($password,PASSWORD_BCRYPT);
            $this->query('INSERT INTO coop_access_commands(actor_user_id,action,idempotency_key,request_hash,secret_hash) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[$actor,$action,$key,$hash,$proof]);
            $receipt=$this->query('SELECT id,request_hash,result_json,secret_hash FROM coop_access_commands WHERE actor_user_id=? AND action=? AND idempotency_key=? FOR UPDATE',[$actor,$action,$key])->fetch(PDO::FETCH_ASSOC);
            if (!$receipt || !hash_equals($receipt['request_hash'],$hash) || ($password!==null && (!is_string($receipt['secret_hash']) || !password_verify($password,$receipt['secret_hash'])))) throw new RuntimeException('Submission reference already used with different access changes',409);
            if ($receipt['result_json']!==null) return array_merge(json_decode($receipt['result_json'],true,512,JSON_THROW_ON_ERROR),['replayed'=>true]);
            if ($action==='create_role') {
                try { $this->query('INSERT INTO coop_access_roles(role_code,display_name,created_by_user_id) VALUES(?,?,?)',[$payload['role_code'],$payload['display_name'],$actor]); }
                catch(PDOException $e) { if((int)($e->errorInfo[1]??0)===1062)throw new RuntimeException('Role code already exists',409);throw $e; }
                $result=['role_id'=>(int)$this->db->lastInsertId()];
            } elseif ($action==='set_permissions') {
                $this->role($payload['role_id']);
                $this->query('DELETE FROM coop_access_role_permissions WHERE role_id=?',[$payload['role_id']]);
                foreach($payload['permissions']as$p)$this->query('INSERT INTO coop_access_role_permissions(role_id,permission_code) VALUES(?,?)',[$payload['role_id'],$p]);
                $result=['role_id'=>$payload['role_id'],'permissions'=>$payload['permissions']];
            } elseif($action==='set_member_roles') {
                if(!$this->query("SELECT user_id FROM tblusers WHERE user_id=? AND BINARY Status='Active' FOR UPDATE",[$payload['staff_user_id']])->fetchColumn())throw new RuntimeException('Active cooperative staff account not found',404);
                foreach($payload['role_ids']as$id)$this->role($id);
                $this->query('DELETE FROM coop_access_staff_roles WHERE staff_user_id=?',[$payload['staff_user_id']]);
                foreach($payload['role_ids']as$id)$this->query('INSERT INTO coop_access_staff_roles(staff_user_id,role_id) VALUES(?,?)',[$payload['staff_user_id'],$id]);
                $result=$payload;
            } else {
                $result=$this->applyUserCommand($action,$payload,$receipt['secret_hash']);
            }
            if(!$this->query("SELECT 1 FROM tblusers u JOIN coop_access_staff_roles sr ON sr.staff_user_id=u.user_id JOIN coop_access_role_permissions rp ON rp.role_id=sr.role_id WHERE BINARY u.Status='Active' AND rp.permission_code='access.roles.manage' LIMIT 1 LOCK IN SHARE MODE")->fetchColumn())throw new RuntimeException('The last active cooperative access administrator must remain assigned',409);
            $this->audit($actor,$action,$result);
            $this->query('UPDATE coop_access_commands SET result_json=? WHERE id=?',[json_encode($result,JSON_THROW_ON_ERROR),$receipt['id']]);
            return array_merge($result,['replayed'=>false]);
        });
    }

    private function validate(string $action,array $p):array
    {
        $fields=['create_role'=>['role_code','display_name'],'set_permissions'=>['role_id','permissions'],'set_member_roles'=>['staff_user_id','role_ids'],'create_staff_user'=>['staff_user_id','username','complete_name','email','password','status'],'update_staff_user'=>['staff_user_id','complete_name','email'],'set_staff_status'=>['staff_user_id','status'],'reset_staff_password'=>['staff_user_id','password']][$action]??null;
        if(!$fields||array_diff($fields,array_keys($p))||array_diff(array_keys($p),$fields))throw new InvalidArgumentException('Unsupported access command or fields',400);
        if(in_array($action,['create_staff_user','update_staff_user','set_staff_status','reset_staff_password'],true)) {
            $out=['staff_user_id'=>self::staffId($p['staff_user_id'])];
            foreach(array_slice($fields,1)as$f) {
                if(!is_string($p[$f]))throw new InvalidArgumentException('Staff account fields must be text',400);
                $v=$f==='password'?$p[$f]:trim($p[$f]);
                if($f==='status'&&!in_array($v,['Active','Inactive'],true))throw new InvalidArgumentException('Explicit Active or Inactive status required',400);
                if($f==='password'&&(strlen($v)<12||strlen($v)>72||str_contains($v,"\0")))throw new InvalidArgumentException('Password must contain 12 to 72 bytes',400);
                if($f==='email'&&(strlen($v)>254||!filter_var($v,FILTER_VALIDATE_EMAIL)))throw new InvalidArgumentException('Valid email required',400);
                if($f==='complete_name'&&($v===''||strlen($v)>150))throw new InvalidArgumentException('Full name required, maximum 150 bytes',400);
                if($f==='username'&&(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.@-]{0,63}\z/',$v)))throw new InvalidArgumentException('Valid username required, maximum 64 ASCII characters',400);
                $out[$f]=$v;
            }
            return $out;
        }
        if($action==='create_role') {
            if(!is_string($p['role_code'])||!preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/',$p['role_code'])||!is_string($p['display_name'])||trim($p['display_name'])===''||strlen($p['display_name'])>150)throw new InvalidArgumentException('Valid role code and display name required',400);
            return ['role_code'=>$p['role_code'],'display_name'=>trim($p['display_name'])];
        }
        if($action==='set_permissions') {
            $id=self::staffId($p['role_id']);
            if(!is_array($p['permissions'])||!array_is_list($p['permissions'])||count($p['permissions'])>count(self::PERMISSIONS))throw new InvalidArgumentException('A bounded permission list is required',400);
            foreach($p['permissions']as$v)if(!is_string($v)||!in_array($v,self::PERMISSIONS,true))throw new InvalidArgumentException('Unknown cooperative permission',400);
            $v=array_values(array_unique($p['permissions']));sort($v,SORT_STRING);return ['role_id'=>$id,'permissions'=>$v];
        }
        $id=self::staffId($p['staff_user_id']);
        if(!is_array($p['role_ids'])||!array_is_list($p['role_ids'])||count($p['role_ids'])>100)throw new InvalidArgumentException('At most 100 role IDs are allowed',400);
        $roles=array_map([self::class,'staffId'],$p['role_ids']);$roles=array_values(array_unique($roles));sort($roles,SORT_NUMERIC);return ['staff_user_id'=>$id,'role_ids'=>$roles];
    }
    public static function permissionForAction(string $action):string
    {
        return in_array($action,['create_staff_user','update_staff_user','set_staff_status','reset_staff_password'],true)?'access.users.manage':'access.roles.manage';
    }

    private function applyUserCommand(string $action,array $p,?string $passwordHash):array
    {
        $id=$p['staff_user_id'];
        if($action==='create_staff_user') {
            if($this->query('SELECT user_id FROM tblusers WHERE user_id=? OR Username=? FOR UPDATE',[$id,$p['username']])->fetchColumn())throw new RuntimeException('Staff ID or username already exists',409);
            $this->query("INSERT INTO tblusers(user_id,Username,CompleteName,Email,UPassword,AdminType,Status,CreatedDate) VALUES(?,?,?,?,?,'User',?,NOW())",[$id,$p['username'],$p['complete_name'],$p['email'],$passwordHash,$p['status']]);
        } else {
            if(!$this->query('SELECT user_id FROM tblusers WHERE user_id=? FOR UPDATE',[$id])->fetchColumn())throw new RuntimeException('Cooperative staff account not found',404);
            if($action==='update_staff_user')$this->query('UPDATE tblusers SET CompleteName=?,Email=? WHERE user_id=?',[$p['complete_name'],$p['email'],$id]);
            elseif($action==='set_staff_status')$this->query('UPDATE tblusers SET Status=? WHERE user_id=?',[$p['status'],$id]);
            else $this->query('UPDATE tblusers SET UPassword=? WHERE user_id=?',[$passwordHash,$id]);
        }
        $result=['staff_user_id'=>$id,'operation'=>$action];
        foreach(['username','complete_name','email']as$field)if(isset($p[$field]))$result[$field]=$p[$field];
        if(isset($p['status']))$result['status']=$p['status'];
        return $result;
    }

    /** Explicit operator-only first cooperative editor. Does not provision platform authority. */
    public function bootstrapForDeployment($staffId,string $key):array
    {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Deployment CLI required',403);
        $id=self::staffId($staffId);
        if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/',$key))throw new InvalidArgumentException('Stable bootstrap reference required',400);
        $hash=hash('sha256','initial-cooperative-editor:'.$id);
        return $this->transaction(function()use($id,$key,$hash){
            $control=$this->query('SELECT bootstrapped_at FROM coop_access_control WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if(!$control)throw new RuntimeException('Cooperative access migration required',409);
            $prior=$this->query("SELECT request_hash,result_json FROM coop_access_commands WHERE actor_user_id=? AND action='bootstrap' AND idempotency_key=? FOR UPDATE",[$id,$key])->fetch(PDO::FETCH_ASSOC);
            if($prior){if(!hash_equals($prior['request_hash'],$hash)||$prior['result_json']===null)throw new RuntimeException('Bootstrap reference conflict',409);return array_merge(json_decode($prior['result_json'],true,512,JSON_THROW_ON_ERROR),['replayed'=>true]);}
            if($control['bootstrapped_at']!==null||(int)$this->query('SELECT COUNT(*) FROM coop_access_roles')->fetchColumn()>0)throw new RuntimeException('Initial cooperative access administration is already configured',409);
            if(!$this->query("SELECT user_id FROM tblusers WHERE user_id=? AND BINARY Status='Active' FOR UPDATE",[$id])->fetchColumn())throw new RuntimeException('Explicit active existing cooperative staff account required',404);
            $this->query("INSERT INTO coop_access_roles(role_code,display_name,created_by_user_id) VALUES('cooperative_access_administrator','Cooperative access administrator',?)",[$id]);$role=(int)$this->db->lastInsertId();
            $this->query("INSERT INTO coop_access_role_permissions(role_id,permission_code) VALUES(?,'access.roles.manage')",[$role]);
            $this->query('INSERT INTO coop_access_staff_roles(staff_user_id,role_id) VALUES(?,?)',[$id,$role]);
            $this->query('UPDATE coop_access_control SET bootstrapped_at=NOW(),bootstrap_staff_user_id=? WHERE id=1',[$id]);
            $result=['staff_user_id'=>$id,'role_id'=>$role,'permission'=>'access.roles.manage'];$this->audit($id,'bootstrap',$result);
            $this->query("INSERT INTO coop_access_commands(actor_user_id,action,idempotency_key,request_hash,result_json) VALUES(?,'bootstrap',?,?,?)",[$id,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return array_merge($result,['replayed'=>false]);
        });
    }

    private function role(int $id):void { if(!$this->query('SELECT id FROM coop_access_roles WHERE id=? FOR UPDATE',[$id])->fetchColumn())throw new RuntimeException('Cooperative role not found',404); }
    private function query(string $sql,array $p=[]):PDOStatement { $s=$this->db->prepare($sql);$s->execute($p);return $s; }
    private function audit(int $actor,string $action,array $result):void
    {
        $table=self::permissionForAction($action)==='access.users.manage'?'tblusers':'coop_access_roles';
        $this->query("INSERT INTO coop_audit_trail(user_id,action_type,table_name,record_id,new_values,ip_address,user_agent) VALUES(?,'update',?,?, ?,?,?)",[$actor,$table,(int)($result['role_id']??$result['staff_user_id']??0),json_encode(['operation'=>$action,'result'=>$result],JSON_THROW_ON_ERROR),$_SERVER['REMOTE_ADDR']??null,substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]);
    }
    private function transaction(callable $operation):array
    {
        $own=!$this->db->inTransaction();$name='coop_access_'.++self::$savepoint;
        if($own)$this->db->beginTransaction();else$this->db->exec('SAVEPOINT '.$name);
        try { $result=$operation();if($own)$this->db->commit();else$this->db->exec('RELEASE SAVEPOINT '.$name);return $result; }
        catch(Throwable $e){if($this->db->inTransaction()){if($own)$this->db->rollBack();else{$this->db->exec('ROLLBACK TO SAVEPOINT '.$name);$this->db->exec('RELEASE SAVEPOINT '.$name);}}throw $e;}
    }
}
