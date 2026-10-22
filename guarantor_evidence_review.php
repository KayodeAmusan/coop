<?php
require_once __DIR__ . '/includes/session_helper.php';
initSession();
require_once __DIR__ . '/includes/admin_access_helper.php';
if (findAdminAccessError($_SESSION) !== null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Administrator access is required to upload and review guarantor evidence.';
    exit;
}
header('Cache-Control: private, no-store');
$initialLoan = $_GET['loan_request_id'] ?? '';
if (!is_string($initialLoan) || !preg_match('/\A[1-9][0-9]{0,9}\z/', $initialLoan) || (int) $initialLoan > 2147483647) $initialLoan = '';
$pageTitle = 'Guarantor signed evidence and staff review';
require_once __DIR__ . '/includes/header.php';
?>
<?php $evidenceStaff = true; require __DIR__ . '/includes/guarantor_evidence_view.php'; ?>
<script src="assets/js/guarantor_evidence.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>