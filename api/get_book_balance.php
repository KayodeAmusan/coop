<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/admin_access_helper.php';
$accessError = findAdminAccessError($_SESSION);
if ($accessError !== null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $accessError]);
    exit;
}
require_once __DIR__ . '/../Connections/coop.php';
require_once __DIR__ . '/../libs/services/AccountBalanceCalculator.php';
try {
    $account_id = filter_var($_GET['account_id'] ?? null, FILTER_VALIDATE_INT);
    $periodid = filter_var($_GET['periodid'] ?? null, FILTER_VALIDATE_INT);
    if (!$account_id || $account_id <= 0 || !$periodid || $periodid <= 0) {
        http_response_code(400);
        throw new InvalidArgumentException('Valid account and period are required');
    }
    $calculator = new AccountBalanceCalculator($coop, $database);
    $balance = $calculator->getAccountBalance($account_id, $periodid);
    if (isset($balance['error'])) {
        http_response_code(500);
        throw new RuntimeException('Book balance is unavailable');
    }
    echo json_encode([
        'success' => true,
        'balance' => $balance['balance'],
        'debit' => $balance['debit'],
        'credit' => $balance['credit'],
        'balance_decimal' => $balance['balance_decimal'],
        'debit_decimal' => $balance['debit_decimal'],
        'credit_decimal' => $balance['credit_decimal']
    ]);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
