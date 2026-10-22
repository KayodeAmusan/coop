<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new RuntimeException('Method not allowed', 405);
    require_once __DIR__ . '/../../utils/JWTHandler.php';
    require_once __DIR__ . '/../../../includes/loan_consent_helper.php';
    require_once __DIR__ . '/../../../libs/services/LoanProductService.php';
    require_once __DIR__ . '/../../config/Database.php';
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (!preg_match('/Bearer\s(\S+)/', $headers['authorization'] ?? '', $matches)) throw new RuntimeException('A member login is required', 401);
    authenticatedLoanMember((new JWTHandler())->validateToken($matches[1]));
    $db = (new Database())->getConnection();
    echo json_encode(['success' => true, 'data' => (new LoanProductService($db))->applicationAvailability()]);
} catch (Throwable $error) {
    $code = $error->getCode();
    http_response_code(in_array($code, [400, 401, 403, 405], true) ? $code : 500);
    echo json_encode(['success' => false, 'message' => $code >= 400 && $code < 500 ? $error->getMessage() : 'Unable to check loan product availability']);
}
