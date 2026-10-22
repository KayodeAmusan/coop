<?php
require_once __DIR__ . '/money_helper.php';
/** Shared ownership and consent checks for the existing two-guarantor loan workflow. */
function authenticatedLoanMember($claims)
{
    $id = is_array($claims) ? ($claims['user_id'] ?? null) : null;
    if ((!is_string($id) && !is_int($id)) || trim((string) $id) === '') {
        throw new Exception('A member login is required', 401);
    }
    return (string) $id;
}

function requireLoanMember($claimed, $authenticated)
{
    if ((!is_string($claimed) && !is_int($claimed)) || (string) $claimed !== $authenticated) {
        throw new Exception('You cannot access another member\'s loan records', 403);
    }
}

function loanAmountMinorUnits($value)
{
    try {
        $minor = moneyMinorUnits($value);
        if ($minor < 0 || str_starts_with((string)$value, '-')) {
            throw new InvalidArgumentException('Loan amounts cannot be negative');
        }
        return $minor;
    } catch (InvalidArgumentException $e) {
        throw new InvalidArgumentException($e->getMessage(), 400, $e);
    }
}

function loanAmountDecimal($minorUnits)
{
    return moneyDecimal($minorUnits);
}

function loanConsentTermsHash(array $loan)
{
    $terms = [
        (string) $loan['requester_coop_id'],
        loanAmountMinorUnits($loan['requested_amount']),
        loanAmountMinorUnits($loan['monthly_repayment'])
    ];
    if (!empty($loan['loan_product_version_id'])) {
        $snapshot = $loan['product_terms_snapshot'] ?? null;
        $hash = $loan['product_terms_hash'] ?? null;
        if (!is_string($snapshot) || !is_string($hash) || !hash_equals(hash('sha256', $snapshot), $hash)) {
            throw new RuntimeException('Valid immutable product terms are required', 409);
        }
        $terms[] = (int) $loan['loan_product_version_id'];
        $terms[] = $hash;
    }
    return hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR));
}

/** Signed approvals are mandatory; a direct click or uploader identity is not staff acceptance. */
function findGuarantorSignedEvidenceError(array $loan, array $guarantor)
{
    $id = (string) ($guarantor['guarantor_coop_id'] ?? '');
    if ($id === '' || $id === (string) $loan['requester_coop_id'] || ($guarantor['member_status'] ?? null) !== 'Active' ||
        !in_array($guarantor['status'] ?? null, ['pending', 'approved'], true)) {
        return 'Each required guarantor must be a distinct active member with a current invitation';
    }
    if (loanAmountMinorUnits($guarantor['requested_amount']) !== loanAmountMinorUnits($loan['requested_amount']) ||
        loanAmountMinorUnits($guarantor['monthly_repayment']) !== loanAmountMinorUnits($loan['monthly_repayment'])) {
        return 'Loan terms have changed; renew the invitation and signed approval';
    }
    $reviewer = $guarantor['evidence_reviewed_by_id'] ?? null;
    $uploader = $guarantor['evidence_uploaded_by_id'] ?? null;
    if (empty($guarantor['evidence_id']) || ($guarantor['evidence_kind'] ?? null) !== 'approval' ||
        (string) ($guarantor['evidence_loan_request_id'] ?? '') !== (string) ($loan['id'] ?? '') ||
        (string) ($guarantor['evidence_guarantor_request_id'] ?? '') !== (string) ($guarantor['id'] ?? '') ||
        ($guarantor['evidence_guarantor_coop_id'] ?? null) !== $id ||
        ($guarantor['evidence_terms_hash'] ?? null) !== loanConsentTermsHash($loan) ||
        ($guarantor['evidence_scan_status'] ?? null) !== 'clean' ||
        ($guarantor['evidence_review_status'] ?? null) !== 'accepted' ||
        ($guarantor['evidence_reviewed_by_type'] ?? null) !== 'staff' ||
        (!is_int($reviewer) && !is_string($reviewer)) || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $reviewer) || (int) $reviewer > 2147483647 ||
        empty($guarantor['evidence_reviewed_at']) || str_starts_with((string) $guarantor['evidence_reviewed_at'], '0000-') ||
        !is_string($guarantor['evidence_content_sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $guarantor['evidence_content_sha256']) ||
        !in_array($guarantor['evidence_uploaded_by_type'] ?? null, ['member', 'staff'], true) ||
        (!is_int($uploader) && !is_string($uploader)) || trim((string) $uploader) === '' ||
        (($guarantor['evidence_uploaded_by_type'] ?? null) === 'staff' &&
            (!preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $uploader) || (int) $uploader > 2147483647 || (int) $uploader === (int) $reviewer))) {
        return 'The latest signed approval for each guarantor must match current terms, pass scanning and be accepted by authorized staff';
    }
    return null;
}

function findLoanGuarantorEvidenceError(array $loan, array $guarantors)
{
    // Transitional legacy count only. New applications remain blocked until product rules are configured.
    if (count($guarantors) !== 2) return 'Two distinct active guarantors need staff-accepted signed approvals for this legacy application';
    $seen = [];
    foreach ($guarantors as $guarantor) {
        $id = (string) ($guarantor['guarantor_coop_id'] ?? '');
        if (isset($seen[$id])) return 'Guarantors must be distinct members other than the borrower';
        $seen[$id] = true;
        $error = findGuarantorSignedEvidenceError($loan, $guarantor);
        if ($error !== null) return $error;
    }
    return null;
}

function findLoanConsentError(array $loan, array $guarantors, $approvedAmount)
{
    if (!in_array($loan['status'], ['submitted', 'fully_guaranteed'], true)) return 'Only submitted loans with staff-accepted signed guarantor approvals can be approved';
    if (loanAmountMinorUnits($approvedAmount) <= 0 || loanAmountMinorUnits($approvedAmount) !== loanAmountMinorUnits($loan['requested_amount'])) return 'Changing the loan amount requires fresh signed guarantor approvals';
    return findLoanGuarantorEvidenceError($loan, $guarantors);
}

/** Loan lock is acquired before invitation/evidence locks by every caller. */
function lockedLoanGuarantorEvidence($db, $loanId)
{
    return loanGuarantorEvidenceRows($db, $loanId, true);
}

function loanGuarantorEvidenceRows($db, $loanId, $lock = false)
{
    if ($lock && !$db->inTransaction()) throw new LogicException('Signed approval verification requires a transaction');
    $stmt = $db->prepare("SELECT gr.*, e.Status AS member_status,
        ev.id AS evidence_id, ev.loan_request_id AS evidence_loan_request_id,
        ev.guarantor_request_id AS evidence_guarantor_request_id,
        ev.guarantor_coop_id AS evidence_guarantor_coop_id,
        ev.evidence_kind, ev.terms_hash AS evidence_terms_hash,
        ev.scan_status AS evidence_scan_status, ev.review_status AS evidence_review_status,
        ev.reviewed_by_type AS evidence_reviewed_by_type, ev.reviewed_by_id AS evidence_reviewed_by_id,
        ev.reviewed_at AS evidence_reviewed_at, ev.content_sha256 AS evidence_content_sha256,
        ev.uploaded_by_type AS evidence_uploaded_by_type, ev.uploaded_by_id AS evidence_uploaded_by_id
        FROM guarantor_requests gr
        LEFT JOIN tblemployees e ON e.CoopID = gr.guarantor_coop_id
        LEFT JOIN loan_guarantor_evidence ev ON ev.id = (
            SELECT latest.id FROM loan_guarantor_evidence latest
            WHERE latest.guarantor_request_id = gr.id AND latest.evidence_kind = 'approval'
            ORDER BY latest.version_number DESC LIMIT 1
        )
        WHERE gr.loan_request_id = :loan_id AND gr.status IN ('pending', 'approved')
        ORDER BY gr.id" . ($lock ? " FOR UPDATE" : ""));
    $stmt->execute([':loan_id' => $loanId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Caller holds the loan row lock for the entire approval transaction. */
function requireLoanConsent($db, array $loan, $approvedAmount)
{
    $error = findLoanConsentError($loan, lockedLoanGuarantorEvidence($db, $loan['id']), $approvedAmount);
    if ($error !== null) throw new Exception($error, 409);
}

/** Call after an evidence upload/review or optional direct response, within the same loan-locked transaction. */
function refreshLoanEvidenceStatus($db, $loanId)
{
    if (!$db->inTransaction()) throw new LogicException('Loan evidence status requires a transaction');
    $stmt = $db->prepare('SELECT * FROM loan_requests WHERE id = :id FOR UPDATE');
    $stmt->execute([':id' => $loanId]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loan) throw new RuntimeException('Loan request not found', 404);
    $result = ['status' => $loan['status'], 'limit_exceeded' => null];
    if (!in_array($loan['status'], ['draft', 'pending_guarantors', 'partially_guaranteed', 'submitted', 'fully_guaranteed'], true)) return $result;
    $guarantors = lockedLoanGuarantorEvidence($db, $loanId);
    $eligible = findLoanGuarantorEvidenceError($loan, $guarantors) === null;
    $accepted = 0;
    foreach ($guarantors as $guarantor) if (findGuarantorSignedEvidenceError($loan, $guarantor) === null) $accepted++;
    $status = $eligible ? 'submitted' : ($accepted > 0 ? 'partially_guaranteed' : 'pending_guarantors');
    if ($eligible) {
        $stmt = $db->prepare('SELECT limit_amount FROM loan_period_limits WHERE period_id = :period LIMIT 1 FOR UPDATE');
        $stmt->execute([':period' => $loan['period_id']]); $limit = $stmt->fetchColumn();
        if ($limit !== false) {
            $stmt = $db->prepare("SELECT COALESCE(SUM(requested_amount), 0) FROM loan_requests WHERE period_id = :period AND status IN ('submitted','fully_guaranteed','approved') AND id != :id FOR UPDATE");
            $stmt->execute([':period' => $loan['period_id'], ':id' => $loanId]); $total = loanAmountMinorUnits($stmt->fetchColumn());
            $amount = loanAmountMinorUnits($loan['requested_amount']);
            if (moneyAdd($total, $amount) > loanAmountMinorUnits($limit)) {
                $status = 'rejected';
                $result['limit_exceeded'] = [$loan['requester_coop_id'], $loan['requested_amount'], loanAmountDecimal(loanAmountMinorUnits($limit)), loanAmountDecimal($total)];
            }
        }
    }
    if ($status !== $loan['status']) {
        $stmt = $db->prepare("UPDATE loan_requests SET status = :status, submitted_at = CASE WHEN :submitted = 1 THEN COALESCE(submitted_at, CURRENT_TIMESTAMP) ELSE NULL END, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':status' => $status, ':submitted' => $status === 'submitted' ? 1 : 0, ':id' => $loanId]);
    }
    $result['status'] = $status;
    return $result;
}
