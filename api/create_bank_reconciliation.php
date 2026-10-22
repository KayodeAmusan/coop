<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/admin_access_helper.php';
$actor = $_SESSION['SESS_MEMBER_ID'] ?? null;
$validActor = (is_int($actor) || is_string($actor)) && preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $actor) && (int) $actor <= 2147483647;
$accessError = $validActor ? findAdminAccessError($_SESSION) : 'A valid authenticated staff account is required';
if ($accessError === null) $accessError = findAdminMutationOriginError($_SERVER);
if ($accessError !== null) {
    http_response_code(403); echo json_encode(['success' => false, 'error' => $accessError]); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); header('Allow: POST'); echo json_encode(['success' => false, 'error' => 'Use POST for this operation']); exit;
}
try {
    $key = $_POST['idempotency_key'] ?? null;
    if (!is_string($key) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/', $key)) throw new InvalidArgumentException('A stable submission reference is required. Reload the reconciliation form.', 400);
    $fields = ['periodid','bank_account_id','reconciliation_date','bank_statement_balance','book_balance','outstanding_deposits','outstanding_withdrawals','bank_charges','bank_interest','notes','idempotency_key'];
    if (array_diff(array_keys($_POST), $fields)) throw new InvalidArgumentException('Unsupported reconciliation fields', 400);
    $data = [];
    foreach ($fields as $field) $data[$field] = $_POST[$field] ?? null;
    $data['reconciled_by'] = (int) $actor;
    require_once __DIR__ . '/../Connections/coop.php';
    require_once __DIR__ . '/../libs/services/BankReconciliationService.php';
    $result = (new BankReconciliationService($coop, $database))->createReconciliation($data);
    if (!$result['success']) {
        $status = $result['error_code'] ?? 500;
        http_response_code(in_array($status, [400,409], true) ? $status : 500);
        echo json_encode(['success' => false, 'error' => $status === 500 ? 'Unable to save the reconciliation. Retry with the same submission reference or contact an administrator.' : $result['error']]);
        exit;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $validation = $error instanceof InvalidArgumentException || $error instanceof OverflowException;
    http_response_code($validation ? 400 : 500);
    if (!$validation) error_log('Bank reconciliation request failed');
    echo json_encode(['success' => false, 'error' => $validation ? $error->getMessage() : 'Unable to save the reconciliation. Retry with the same submission reference or contact an administrator.']);
}
