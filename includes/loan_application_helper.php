<?php
// Both creation paths lock the borrower before checking applications, including an empty period.
function lockLoanApplicationBorrower($db, $coopId, $periodId) {
    if (!$db->inTransaction()) {
        throw new LogicException('Loan application checks require a transaction');
    }
    $stmt = $db->prepare("SELECT CoopID, StaffID FROM tblemployees WHERE CoopID = :coop_id AND Status = 'Active' LIMIT 1 FOR UPDATE");
    $stmt->execute([':coop_id' => $coopId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        throw new Exception('Member not found or inactive', 404);
    }
    $stmt = $db->prepare("SELECT id FROM loan_requests WHERE requester_coop_id = :coop_id AND period_id = :period_id AND status IN ('draft', 'pending_guarantors', 'partially_guaranteed', 'fully_guaranteed', 'submitted', 'approved') LIMIT 1 FOR UPDATE");
    $stmt->execute([':coop_id' => $coopId, ':period_id' => $periodId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Member already has a loan request for this payroll period. Please check the existing request.', 409);
    }
    return $member;
}
