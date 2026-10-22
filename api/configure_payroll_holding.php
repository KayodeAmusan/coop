<?php
require_once __DIR__ . '/../includes/session_helper.php';
initSession();
require_once __DIR__ . '/../includes/admin_access_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$accessError = findAdminAccessError($_SESSION);
if ($accessError !== null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $accessError]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Use POST to save payroll holding setup.']);
    exit;
}
$originError = findAdminMutationOriginError($_SERVER);
if ($originError !== null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $originError]);
    exit;
}
$accountId = $_POST['holding_account_id'] ?? null;
$actorId = $_SESSION['SESS_MEMBER_ID'];
foreach ([$accountId, $actorId] as $identifier) {
    if ((!is_int($identifier) && !is_string($identifier)) || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $identifier) ||
        (strlen((string) $identifier) === 10 && strcmp((string) $identifier, '2147483647') > 0)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Select a valid existing ledger account and sign in with a valid administrator account.']);
        exit;
    }
}
$started = false;
try {
    require_once __DIR__ . '/../Connections/coop.php';
    require_once __DIR__ . '/../includes/payroll_holding_helper.php';
    $coop->beginTransaction();
    $started = true;
    $configuration = configurePayrollHoldingAccount($coop, $accountId, $actorId);
    $coop->commit();
    $started = false;
    echo json_encode(['success' => true, 'message' => 'Payroll holding account saved.',
        'holding_account_id' => (string) $configuration['holding_account_id']]);
} catch (Throwable $error) {
    if ($started && isset($coop) && $coop->inTransaction()) { $coop->rollBack(); }
    $invalid = $error instanceof InvalidArgumentException;
    http_response_code($invalid ? 400 : 500);
    error_log('Payroll holding setup: ' . $error->getMessage());
    echo json_encode(['success' => false, 'error' => $invalid ? $error->getMessage() : 'Payroll holding setup could not be confirmed. Reload setup to check the current account before trying again.']);
}

