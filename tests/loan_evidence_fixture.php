<?php
/** Minimal portable signed-evidence fixture; real migration coverage lives in the MariaDB scripts. */
function createTestLoanEvidenceTable(PDO $db): void {
    $db->exec('CREATE TABLE loan_guarantor_evidence (id INTEGER PRIMARY KEY,loan_request_id INTEGER,guarantor_request_id INTEGER,guarantor_coop_id VARCHAR(255),evidence_kind VARCHAR(30),version_number INTEGER,terms_hash CHAR(64),scan_status VARCHAR(30),review_status VARCHAR(30),reviewed_by_type VARCHAR(30),reviewed_by_id VARCHAR(255),reviewed_at VARCHAR(30),content_sha256 CHAR(64),uploaded_by_type VARCHAR(30),uploaded_by_id VARCHAR(255))');
}
function seedTestLoanEvidence(PDO $db,int $loanId,int $invitationId,string $guarantor,string $hash,int $version=1,string $review='accepted'): void {
    $db->prepare('INSERT INTO loan_guarantor_evidence VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$invitationId*100+$version,$loanId,$invitationId,$guarantor,'approval',$version,$hash,'clean',$review,'staff','7','2026-10-21 12:00:00',str_repeat('a',64),'member','borrower']);
}
