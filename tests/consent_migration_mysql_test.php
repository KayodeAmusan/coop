<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/loan_consent_helper.php';
if (getenv('COOP_CONSENT_MYSQL_TEST') !== '1') {
    echo "SKIP isolated MariaDB migration tests (set COOP_CONSENT_MYSQL_TEST=1)\n";
    exit(0);
}
// Fixed loopback port/database deliberately cannot target an application database.
$server = new PDO('mysql:host=127.0.0.1;port=13307;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$server->exec('CREATE DATABASE IF NOT EXISTS coop_test_consent CHARACTER SET utf8mb4');
$db = new PDO('mysql:host=127.0.0.1;port=13307;dbname=coop_test_consent;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
function runConsentSql($db, $sql) {
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $stmt = $db->query($sql);
    do { if ($stmt->columnCount()) $stmt->fetchAll(); } while ($stmt->nextRowset());
    $stmt->closeCursor();
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
}
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['loan_guarantor_evidence_history','loan_guarantor_evidence','loan_guarantor_response_history','guarantor_requests','loan_requests','tblemployees'] as $table) {
    $db->exec("DROP TABLE IF EXISTS $table");
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');
$db->exec("CREATE TABLE tblemployees (CoopID VARCHAR(255) PRIMARY KEY, Status VARCHAR(20)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
runConsentSql($db, file_get_contents(__DIR__ . '/../database/migrations/create_loan_request_tables_simple.sql'));
runConsentSql($db, file_get_contents(__DIR__ . '/../database/migrations/add_approval_fields_to_loan_requests.sql'));
runConsentSql($db, file_get_contents(__DIR__ . '/../database/migrations/add_period_id_to_loan_requests.sql'));
$db->exec("INSERT INTO tblemployees VALUES ('synthetic-borrower','Active'),('synthetic-g1','Active'),('synthetic-g2','Active')");
$db->exec("INSERT INTO loan_requests (id,requester_coop_id,requested_amount,monthly_repayment,status,period_id)
    VALUES (1,'synthetic-borrower',100.10,10.01,'submitted',1)");
$db->exec("INSERT INTO guarantor_requests (id,loan_request_id,guarantor_coop_id,requester_name,requested_amount,monthly_repayment,status,response_date)
    VALUES (1,1,'synthetic-g1','Synthetic member',100.10,10.01,'approved','2026-10-21 10:00:00'),
           (2,1,'synthetic-g2','Synthetic member',100.10,10.01,'approved','2026-10-21 10:00:00')");
$migration = file_get_contents(__DIR__ . '/../database/migrations/add_authenticated_guarantor_consent.sql');
test('applies the real additive migration twice without changing legacy decisions or amounts', function() use ($db,$migration) {
    runConsentSql($db,$migration);
    runConsentSql($db,$migration);
    assertSameValue(2,(int)$db->query('SELECT COUNT(*) FROM guarantor_requests')->fetchColumn());
    assertSameValue(2,(int)$db->query("SELECT COUNT(*) FROM guarantor_requests WHERE status='approved' AND responded_by_coop_id IS NULL AND consent_terms_hash IS NULL")->fetchColumn());
    assertSameValue('100.10',$db->query('SELECT requested_amount FROM loan_requests WHERE id=1')->fetchColumn());
});
runConsentSql($db, file_get_contents(__DIR__ . '/../database/migrations/20261021_guarantor_evidence.sql'));
$loan=$db->query('SELECT * FROM loan_requests WHERE id=1')->fetch(PDO::FETCH_ASSOC);
test('legacy approval labels cannot substitute for authenticated consent on MariaDB', function() use($db,$loan) {
    $db->beginTransaction();
    try {
        requireLoanConsent($db,$loan,'100.10');
        throw new RuntimeException('Legacy rows incorrectly accepted');
    } catch (Exception $e) {
        assertSameValue(409,$e->getCode());
    } finally {$db->rollBack();}
});
test('authenticated direct clicks remain insufficient without reviewed signed approval', function() use($db,$loan) {
    $db->prepare('UPDATE guarantor_requests SET responded_by_coop_id=guarantor_coop_id, consent_terms_hash=?')->execute([loanConsentTermsHash($loan)]);
    $db->beginTransaction();
    try { requireLoanConsent($db,$loan,'100.10'); throw new RuntimeException('Direct click bypassed signed evidence'); }
    catch(Exception $error){assertSameValue(409,$error->getCode());} finally {$db->rollBack();}
});
test('current clean staff-accepted signed approvals satisfy the actual locked join', function() use($db,$loan) {
    foreach([1=>'synthetic-g1',2=>'synthetic-g2'] as $id=>$guarantor) {
        $db->prepare("INSERT INTO loan_guarantor_evidence (id,public_id,upload_key_hash,upload_fingerprint,loan_request_id,guarantor_request_id,guarantor_coop_id,evidence_kind,version_number,terms_hash,terms_snapshot,invitation_snapshot,storage_key,content_sha256,byte_size,mime_type,scan_status,uploaded_by_type,uploaded_by_id,review_status,reviewed_by_type,reviewed_by_id,reviewed_at) VALUES (?,?,REPEAT(?,64),REPEAT('f',64),1,?,?,'approval',1,?,'{}','{}',?,?,10,'application/pdf','clean','member','synthetic-borrower','accepted','staff','7',CURRENT_TIMESTAMP)")->execute([$id,str_pad((string)$id,32,'a'),(string)$id,$id,$guarantor,loanConsentTermsHash($loan),str_pad((string)$id,64,'a'),str_repeat('b',64)]);
    }
    $stmt=$db->prepare('UPDATE guarantor_requests SET responded_by_coop_id=guarantor_coop_id, consent_terms_hash=?');
    $stmt->execute([loanConsentTermsHash($loan)]);
    $db->beginTransaction();
    try { requireLoanConsent($db,$loan,'100.10'); } finally { $db->rollBack(); }
});
test('actual loan row locks prevent a second connection modifying an approval concurrently', function() use($db) {
    $other = new PDO('mysql:host=127.0.0.1;port=13307;dbname=coop_test_consent;charset=utf8mb4','root','',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    $db->beginTransaction();
    $db->query('SELECT id FROM loan_requests WHERE id=1 FOR UPDATE')->fetchAll();
    try {
        $other->exec("UPDATE loan_requests SET status='approved' WHERE id=1");
        throw new RuntimeException('Concurrent write bypassed row lock');
    } catch (PDOException $e) {
        assertSameValue(1205,(int)$e->errorInfo[1]);
    } finally {$db->rollBack();}
    assertSameValue('submitted',$db->query('SELECT status FROM loan_requests WHERE id=1')->fetchColumn());
});
test('loan lock serializes proof review and approval; committed rejection is rechecked', function() use($db,$loan) {
    $other=new PDO('mysql:host=127.0.0.1;port=13307;dbname=coop_test_consent;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    $db->beginTransaction();$db->query('SELECT id FROM loan_requests WHERE id=1 FOR UPDATE')->fetchAll();
    $db->exec("UPDATE loan_guarantor_evidence SET review_status='rejected' WHERE id=2");
    $other->beginTransaction();
    try {$other->query('SELECT id FROM loan_requests WHERE id=1 FOR UPDATE')->fetchAll();throw new RuntimeException('Review lock bypassed');}
    catch(PDOException$error){assertSameValue(1205,(int)$error->errorInfo[1]);}
    finally {if($other->inTransaction())$other->rollBack();$db->commit();}
    $other->beginTransaction();
    try { $other->query('SELECT id FROM loan_requests WHERE id=1 FOR UPDATE')->fetchAll();requireLoanConsent($other,$loan,'100.10');throw new RuntimeException('Committed review rejection ignored'); }
    catch(Exception$error){assertSameValue(409,$error->getCode());} finally {$other->rollBack();}
    $db->exec("UPDATE loan_guarantor_evidence SET review_status='accepted' WHERE id=2");
});
test('latest rejected upload cannot fall back to an older accepted approval', function() use($db,$loan) {
    $db->exec("INSERT INTO loan_guarantor_evidence (public_id,upload_key_hash,upload_fingerprint,loan_request_id,guarantor_request_id,guarantor_coop_id,evidence_kind,version_number,terms_hash,terms_snapshot,invitation_snapshot,storage_key,content_sha256,byte_size,mime_type,scan_status,uploaded_by_type,uploaded_by_id,review_status) SELECT REPEAT('c',32),REPEAT('c',64),REPEAT('f',64),loan_request_id,guarantor_request_id,guarantor_coop_id,evidence_kind,2,terms_hash,terms_snapshot,invitation_snapshot,REPEAT('c',64),content_sha256,byte_size,mime_type,'clean',uploaded_by_type,uploaded_by_id,'rejected' FROM loan_guarantor_evidence WHERE id=1");
    $db->beginTransaction();
    try { requireLoanConsent($db,$loan,'100.10'); throw new RuntimeException('Old accepted proof was reused'); }
    catch(Exception $error){assertSameValue(409,$error->getCode());} finally {$db->rollBack();}
});
finishTests();
