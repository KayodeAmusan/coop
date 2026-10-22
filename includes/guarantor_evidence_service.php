<?php
require_once __DIR__ . '/private_document_storage.php';
require_once __DIR__ . '/loan_consent_helper.php';

/** Trusted internal authorization is mandatory; no client supplied callback or scan result is accepted. */
class GuarantorEvidenceService {
    private $db;
    private PrivateDocumentStorage $storage;
    private $authorize;
    public function __construct($db, PrivateDocumentStorage $storage, callable $authorize) {
        $this->db = $db; $this->storage = $storage; $this->authorize = $authorize;
    }
    private function actor(array $actor): array {
        if (!in_array($actor['type'] ?? null, ['member','staff'], true) ||
            (!is_string($actor['id'] ?? null) && !is_int($actor['id'] ?? null)) ||
            trim((string)$actor['id']) === '' || strlen((string)$actor['id']) > 255 || preg_match('/[\x00-\x1f\x7f]/', (string)$actor['id'])) throw new InvalidArgumentException('Authenticated uploader identity is required');
        if($actor['type']==='staff'&&(!preg_match('/\A[1-9][0-9]*\z/',(string)$actor['id'])||(int)$actor['id']>2147483647))throw new InvalidArgumentException('A valid authenticated staff identity is required');
        return ['type'=>$actor['type'],'id'=>(string)$actor['id']];
    }
    private function allow(string $action, array $actor, array $context): void {
        if (($this->authorize)($action, $actor, $context) !== true) throw new RuntimeException('You cannot access this guarantor evidence',403);
    }
    private function begin(): void {
        if ($this->db->inTransaction()) throw new LogicException('Evidence operations own their complete transaction');
        // Invitation discovery precedes the loan lock; each later read must see the lock winner's committed versions.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
    }
    private function invitation($invitationId): array {
        if ((!is_string($invitationId) && !is_int($invitationId)) || !preg_match('/\A[1-9][0-9]*\z/',(string)$invitationId) || (int)$invitationId > 2147483647) throw new InvalidArgumentException('A guarantor invitation identifier is required');
        $stmt=$this->db->prepare('SELECT loan_request_id FROM guarantor_requests WHERE id=?');$stmt->execute([$invitationId]);$loanId=$stmt->fetchColumn();
        if (!$loanId) throw new RuntimeException('Guarantor invitation not found',404);
        $stmt=$this->db->prepare('SELECT * FROM loan_requests WHERE id=? FOR UPDATE');$stmt->execute([$loanId]);$loan=$stmt->fetch(PDO::FETCH_ASSOC);
        $stmt=$this->db->prepare('SELECT * FROM guarantor_requests WHERE id=? FOR UPDATE');$stmt->execute([$invitationId]);$invitation=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$loan || !$invitation || (int)$invitation['loan_request_id']!==(int)$loan['id']) throw new RuntimeException('Guarantor invitation changed; reload before continuing',409);
        return ['loan'=>$loan,'invitation'=>$invitation];
    }
    private function document(string $publicId): array {
        if (!preg_match('/\A[a-f0-9]{32}\z/',$publicId)) throw new RuntimeException('Evidence not found',404);
        $stmt=$this->db->prepare('SELECT guarantor_request_id FROM loan_guarantor_evidence WHERE public_id=?');$stmt->execute([$publicId]);$invitationId=$stmt->fetchColumn();
        if (!$invitationId) throw new RuntimeException('Evidence not found',404);
        $context=$this->invitation($invitationId);
        $stmt=$this->db->prepare('SELECT e.*, (SELECT MAX(v.version_number) FROM loan_guarantor_evidence v WHERE v.guarantor_request_id=e.guarantor_request_id AND v.evidence_kind=e.evidence_kind) AS latest_version FROM loan_guarantor_evidence e WHERE e.public_id=? FOR UPDATE');$stmt->execute([$publicId]);$context['evidence']=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$context['evidence']) throw new RuntimeException('Evidence not found',404);
        return $context;
    }
    private function event(array $document,string $type,array $actor,array $metadata): void {
        $stmt=$this->db->prepare('INSERT INTO loan_guarantor_evidence_history (evidence_id,event_type,actor_type,actor_id,event_metadata) VALUES (?,?,?,?,?)');
        $stmt->execute([$document['id'],$type,$actor['type'],$actor['id'],json_encode($metadata,JSON_THROW_ON_ERROR)]);
    }
    private function publicMetadata(array $row, array $loan): array {
        $result = array_intersect_key($row,array_flip(['public_id','loan_request_id','guarantor_request_id','guarantor_coop_id','evidence_kind','version_number','terms_hash','content_sha256','byte_size','mime_type','scan_status','uploaded_by_type','uploaded_by_id','uploaded_at','review_status','reviewed_by_type','reviewed_by_id','reviewed_at','review_notes']));
        $result['current_terms'] = hash_equals(loanConsentTermsHash($loan), $row['terms_hash']);
        $result['is_latest'] = (int)$row['latest_version'] === (int)$row['version_number'];
        return $result;
    }
    private function mutable(array $context): void {
        if (in_array($context['loan']['status'],['approved','disbursed','cancelled','rejected'],true) || $context['invitation']['status']==='rejected') throw new RuntimeException('Renew a rejected invitation or use a controlled amendment for finalized loans',409);
    }
    public function upload($invitationId,string $kind,array $file,array $actor,string $uploadKey): array {
        $actor=$this->actor($actor);
        if(!preg_match('/\A[A-Za-z0-9_-]{16,128}\z/',$uploadKey))throw new InvalidArgumentException('A stable upload command key is required');
        if (!in_array($kind,['acknowledgement','approval'],true)) throw new InvalidArgumentException('Select acknowledgement or signed approval evidence');
        $stored=null;$commitAttempted=false;$this->begin();
        try {
            $context=$this->invitation($invitationId);$context['kind']=$kind;$this->allow('upload',$actor,$context);
            $stored=$this->storage->storeUploadedFile($file);
            $termsHash=loanConsentTermsHash($context['loan']);
            $keyHash=hash('sha256',json_encode([$actor['type'],$actor['id'],$uploadKey],JSON_THROW_ON_ERROR));
            $fingerprint=hash('sha256',json_encode([(int)$invitationId,$kind,$termsHash,$stored['content_sha256'],$stored['byte_size'],$stored['mime_type']],JSON_THROW_ON_ERROR));
            $retry=$this->db->prepare('SELECT e.*, (SELECT MAX(v.version_number) FROM loan_guarantor_evidence v WHERE v.guarantor_request_id=e.guarantor_request_id AND v.evidence_kind=e.evidence_kind) AS latest_version FROM loan_guarantor_evidence e WHERE e.upload_key_hash=? FOR UPDATE');$retry->execute([$keyHash]);$existing=$retry->fetch(PDO::FETCH_ASSOC);
            if($existing){
                if(!hash_equals($existing['upload_fingerprint'],$fingerprint))throw new RuntimeException('Upload command key conflicts with different evidence or terms',409);
                $result=$this->publicMetadata($existing,$context['loan']);$this->storage->remove($stored['storage_key']);$stored=null;
                $commitAttempted=true;$this->db->commit();return $result;
            }
            $this->mutable($context);
            $stmt=$this->db->prepare('SELECT COALESCE(MAX(version_number),0) FROM loan_guarantor_evidence WHERE guarantor_request_id=? AND evidence_kind=?');$stmt->execute([$invitationId,$kind]);$version=(int)$stmt->fetchColumn()+1;
            $loan=$context['loan'];$invitation=$context['invitation'];$termsHash=loanConsentTermsHash($loan);
            $snapshot=json_encode(['loan_request_id'=>$loan['id'],'borrower_coop_id'=>$loan['requester_coop_id'],'guarantor_coop_id'=>$invitation['guarantor_coop_id'],'requested_amount'=>$loan['requested_amount'],'monthly_repayment'=>$loan['monthly_repayment'],'product_version_id'=>$loan['loan_product_version_id']??null,'product_terms_hash'=>$loan['product_terms_hash']??null],JSON_THROW_ON_ERROR);
            $publicId=bin2hex(random_bytes(16));
            $stmt=$this->db->prepare('INSERT INTO loan_guarantor_evidence (public_id,upload_key_hash,upload_fingerprint,loan_request_id,guarantor_request_id,guarantor_coop_id,evidence_kind,version_number,terms_hash,terms_snapshot,invitation_snapshot,storage_key,content_sha256,byte_size,mime_type,scan_status,scan_engine,uploaded_by_type,uploaded_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$publicId,$keyHash,$fingerprint,$loan['id'],$invitation['id'],$invitation['guarantor_coop_id'],$kind,$version,$termsHash,$snapshot,json_encode($invitation,JSON_THROW_ON_ERROR),$stored['storage_key'],$stored['content_sha256'],$stored['byte_size'],$stored['mime_type'],$stored['scan_status'],$stored['scan_engine'],$actor['type'],$actor['id']]);
            $document=['id'=>$this->db->lastInsertId()];$this->event($document,'upload',$actor,['version'=>$version,'terms_hash'=>$termsHash,'content_sha256'=>$stored['content_sha256'],'scan_status'=>$stored['scan_status']]);
            refreshLoanEvidenceStatus($this->db,$loan['id']);
            $row=$this->document($publicId)['evidence'];$result=$this->publicMetadata($row,$loan);
            $commitAttempted=true;$this->db->commit();return $result;
        } catch(Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            // A failed commit acknowledgement may still mean the record committed; retain its file for reconciliation.
            if ($stored && !$commitAttempted) $this->storage->remove($stored['storage_key']);
            if($error instanceof PDOException && in_array((string)$error->getCode(),['23000','40001'],true))throw new RuntimeException('Upload command conflicts with another request; reload evidence history',409,$error);
            throw $error;
        }
    }
    public function requestsForLoan($loanId,array $actor): array {
        $actor=$this->actor($actor);
        if((!is_string($loanId)&&!is_int($loanId))||!preg_match('/\A[1-9][0-9]*\z/',(string)$loanId)||(int)$loanId>2147483647)throw new InvalidArgumentException('A loan request identifier is required');
        $this->begin();
        try{
            $stmt=$this->db->prepare('SELECT * FROM loan_requests WHERE id=? FOR UPDATE');$stmt->execute([$loanId]);$loan=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$loan)throw new RuntimeException('Loan request not found',404);
            $stmt=$this->db->prepare('SELECT id,guarantor_coop_id,status FROM guarantor_requests WHERE loan_request_id=? ORDER BY id FOR UPDATE');$stmt->execute([$loanId]);$requests=[];
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC)as$invitation){
                if(($this->authorize)('view',$actor,['loan'=>$loan,'invitation'=>$invitation])===true)$requests[]=['guarantor_request_id'=>$invitation['id'],'guarantor_coop_id'=>$invitation['guarantor_coop_id'],'status'=>$invitation['status']];
            }
            if(!$requests)$this->allow('view',$actor,['loan'=>$loan,'invitation'=>['guarantor_coop_id'=>'']]);
            $result=['loan_request_id'=>$loan['id'],'requester_coop_id'=>$loan['requester_coop_id'],'loan_status'=>$loan['status'],'terms_hash'=>loanConsentTermsHash($loan),'requests'=>$requests];$this->db->commit();return $result;
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    public function listForInvitation($invitationId,array $actor,int $limit=20,?int $beforeId=null): array {
        if($limit<1||$limit>50||($beforeId!==null&&$beforeId<1))throw new InvalidArgumentException('Invalid evidence page');
        $actor=$this->actor($actor);$this->begin();
        try {$context=$this->invitation($invitationId);$this->allow('view',$actor,$context);
            $sql='SELECT e.*, (SELECT MAX(v.version_number) FROM loan_guarantor_evidence v WHERE v.guarantor_request_id=e.guarantor_request_id AND v.evidence_kind=e.evidence_kind) AS latest_version FROM loan_guarantor_evidence e WHERE e.guarantor_request_id=?';$parameters=[$invitationId];
            if($beforeId!==null){$sql.=' AND e.id < ?';$parameters[]=$beforeId;}
            $sql.=' ORDER BY e.id DESC LIMIT '.($limit+1);$stmt=$this->db->prepare($sql);$stmt->execute($parameters);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            $hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);
            $result=['items'=>array_map(fn($row)=>$this->publicMetadata($row,$context['loan']),$rows),'next_cursor'=>$hasMore?(string)end($rows)['id']:null];$this->db->commit();return $result;
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    public function reviewSupportingEvidence(string $publicId,string $decision,string $notes,array $actor): array {
        $actor=$this->actor($actor);
        if($actor['type']!=='staff' || !preg_match('/\A[1-9][0-9]*\z/',$actor['id']) || (int)$actor['id']>2147483647)throw new RuntimeException('Staff review is required',403);
        if(!in_array($decision,['accepted','rejected'],true) || strlen($notes)>4000 || ($decision==='rejected'&&trim($notes)===''))throw new InvalidArgumentException('Accept or reject evidence; rejection requires a reason');
        $this->begin();
        try {$context=$this->document($publicId);$this->allow('review',$actor,$context);$this->mutable($context);$row=$context['evidence'];
            if($row['uploaded_by_type']==='staff'&&$row['uploaded_by_id']===$actor['id'])throw new RuntimeException('A different staff reviewer must review staff-uploaded evidence',409);
            if($row['review_status']!=='pending'){
                if($row['review_status']===$decision && $row['reviewed_by_type']===$actor['type'] && $row['reviewed_by_id']===$actor['id'] && $row['review_notes']===trim($notes)){
                    $result=$this->publicMetadata($row,$context['loan']);$this->db->commit();return $result;
                }
                throw new RuntimeException('Reviewed evidence is immutable; upload a new version for another review',409);
            }
            if($row['scan_status']!=='clean')throw new RuntimeException('Evidence must pass configured malware scanning before review',409);
            if(!hash_equals(loanConsentTermsHash($context['loan']),$row['terms_hash']))throw new RuntimeException('Evidence refers to superseded loan terms',409);
            $stmt=$this->db->prepare('SELECT MAX(version_number) FROM loan_guarantor_evidence WHERE guarantor_request_id=? AND evidence_kind=?');$stmt->execute([$row['guarantor_request_id'],$row['evidence_kind']]);
            if((int)$stmt->fetchColumn()!==(int)$row['version_number'])throw new RuntimeException('Review the latest evidence version',409);
            $stmt=$this->db->prepare('UPDATE loan_guarantor_evidence SET review_status=?,reviewed_by_type=?,reviewed_by_id=?,reviewed_at=NOW(),review_notes=? WHERE id=?');$stmt->execute([$decision,$actor['type'],$actor['id'],trim($notes),$row['id']]);
            $this->event($row,'review',$actor,['previous_status'=>$row['review_status'],'decision'=>$decision,'notes'=>trim($notes),'terms_hash'=>$row['terms_hash']]);
            refreshLoanEvidenceStatus($this->db,$context['loan']['id']);
            $result=$this->publicMetadata($this->document($publicId)['evidence'],$context['loan']);$this->db->commit();return $result;
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    public function rescan(string $publicId,array $actor): array {
        $actor=$this->actor($actor);if($actor['type']!=='staff')throw new RuntimeException('Staff scanning authorization is required',403);$this->begin();
        try {$context=$this->document($publicId);$this->allow('scan',$actor,$context);$this->mutable($context);$row=$context['evidence'];if($row['review_status']!=='pending')throw new RuntimeException('Reviewed evidence requires a controlled amendment before rescanning',409);$result=$this->storage->scan($row);
            $stmt=$this->db->prepare("UPDATE loan_guarantor_evidence SET scan_status=?,scan_engine=?,review_status='pending',reviewed_by_type=NULL,reviewed_by_id=NULL,reviewed_at=NULL,review_notes=NULL WHERE id=?");$stmt->execute([$result['scan_status'],$result['scan_engine'],$row['id']]);
            $this->event($row,'scan',$actor,$result);refreshLoanEvidenceStatus($this->db,$context['loan']['id']);$row=$this->publicMetadata($this->document($publicId)['evidence'],$context['loan']);$this->db->commit();return $row;
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    public function download(string $publicId,array $actor): void {
        $actor=$this->actor($actor);$handle=null;$this->begin();
        try {$context=$this->document($publicId);$this->allow('download',$actor,$context);$row=$context['evidence'];$handle=$this->storage->openCleanDocument($row);
            $this->event($row,'download',$actor,['content_sha256'=>$row['content_sha256']]);$this->db->commit();
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();if(is_resource($handle))fclose($handle);throw $error;}
        $extension=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$row['mime_type']];
        header('Content-Type: '.$row['mime_type']);header('Content-Length: '.$row['byte_size']);header('Content-Disposition: attachment; filename="guarantor-evidence-'.$publicId.'.'.$extension.'"');
        header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');
        fpassthru($handle);fclose($handle);
    }
}
