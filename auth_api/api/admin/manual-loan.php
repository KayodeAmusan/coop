<?php
// api/admin/manual-loan.php
// Admin API for manually creating loan requests

ob_clean();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once __DIR__ . '/../../config/Database.php';
    require_once __DIR__ . '/../../../includes/loan_consent_helper.php';
    require_once __DIR__ . '/../../../libs/services/LoanProductService.php';
    
    // Start session for admin authentication
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    require_once __DIR__ . '/../../../includes/admin_access_helper.php';
    $originError = findAdminMutationOriginError($_SERVER);
    if ($originError !== null) throw new Exception($originError, 403);

    // Check if user is admin
    if (!isset($_SESSION['SESS_MEMBER_ID']) || (trim($_SESSION['SESS_MEMBER_ID']) == '') || $_SESSION['role'] !== 'Admin') {
        throw new Exception('Admin access required', 403);
    }

    $database = new Database();
    $db = $database->getConnection();
    $adminUsername = $_SESSION['complete_name'] ?? 'Admin';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        createManualLoan($db, $adminUsername);
    } else {
        throw new Exception('Method not allowed', 405);
    }

} catch (Exception $e) {
    error_log("Manual loan API error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    $status_code = $e->getCode();
    if (!is_int($status_code) || $status_code < 100 || $status_code > 599) {
        $status_code = 400;
    }

    http_response_code($status_code);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function createManualLoan($db, $adminUsername) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) throw new Exception('Invalid JSON data', 400);
    $borrowerType = $input['borrower_type'] ?? 'member';
    if (!in_array($borrowerType, ['member', 'nonmember'], true)) {
        throw new Exception('borrower_type must be member or nonmember', 400);
    }
    (new LoanProductService($db))->requireApplicationTerms($input['product_version_id'] ?? null, $borrowerType);
}
