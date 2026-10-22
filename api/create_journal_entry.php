<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/admin_access_helper.php';
$accessError = findAdminAccessError($_SESSION) ?? findAdminMutationOriginError($_SERVER);
if ($accessError !== null) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>$accessError]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Use POST for this operation']);
    exit;
}
require_once __DIR__ . '/../Connections/coop.php';
require_once('../libs/services/AccountingEngine.php');
try {
    // Validate required fields
    if (empty($_POST['periodid']) || empty($_POST['entry_date']) || empty($_POST['description']) || empty($_POST['lines'])) {
        throw new Exception('Missing required fields');
    }
    
    $periodid = intval($_POST['periodid']);
    $entry_date = $_POST['entry_date'];
    $entry_type = $_POST['entry_type'] ?? 'manual';
    $description = trim($_POST['description']);
    $source_document = !empty($_POST['source_document']) ? trim($_POST['source_document']) : null;
    $created_by = $_SESSION['SESS_MEMBER_ID'];
    $idempotencyKey = $_POST['idempotency_key'] ?? '';
    if (!is_string($idempotencyKey) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/', $idempotencyKey)) {
        throw new InvalidArgumentException('Reload the journal form to obtain a valid submission reference', 400);
    }
    
    // Parse journal lines
    $lines = [];
    foreach ($_POST['lines'] as $line_data) {
        $account_id = intval($line_data['account_id'] ?? 0);
        $debit = moneyDecimal(moneyMinorUnits(($line_data['debit'] ?? '') === '' ? '0.00' : $line_data['debit']));
        $credit = moneyDecimal(moneyMinorUnits(($line_data['credit'] ?? '') === '' ? '0.00' : $line_data['credit']));
        $line_description = trim($line_data['description'] ?? '');
        
        // Skip empty lines
        if ($account_id == 0 || ($debit == 0 && $credit == 0)) {
            continue;
        }
        
        $lines[] = [
            'account_id' => $account_id,
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'description' => $line_description
        ];
    }
    
    if (count($lines) < 2) {
        throw new Exception('Journal entry must have at least 2 lines');
    }
    
    // Create accounting engine
    $accountingEngine = new AccountingEngine($coop, $database);
    
    $coop->beginTransaction();
    // Create journal entry
    $result = $accountingEngine->createJournalEntry(
        $periodid,
        $entry_date,
        $entry_type,
        $description,
        $lines,
        $created_by,
        $source_document,
        $idempotencyKey
    );
    
    if (!$result['success']) {
        throw new Exception($result['error'], str_contains($result['error'], 'Idempotency key') ? 409 : 400);
    }
    
    // Post the entry immediately
    $post_result = $accountingEngine->postEntry($result['entry_id']);
    
    if (!$post_result['success']) {
        throw new Exception('Entry created but failed to post: ' . $post_result['error']);
    }
    
    $coop->commit();
    // Success
    echo json_encode([
        'success' => true,
        'entry_id' => $result['entry_id'],
        'entry_number' => $result['entry_number'],
        'total_amount' => $result['total_amount']
    ]);
    
} catch (Throwable $e) {
    if (isset($coop) && $coop->inTransaction()) $coop->rollBack();
    http_response_code(in_array($e->getCode(), [400, 409], true) ? $e->getCode() : 500);
    error_log("Manual journal entry error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
