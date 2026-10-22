<?php
// api/loans/request.php
// Create and manage loan requests

ob_clean();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once __DIR__ . '/../../config/Database.php';
    require_once __DIR__ . '/../../../includes/loan_consent_helper.php';
    require_once __DIR__ . '/../../../libs/services/LoanProductService.php';
    require_once __DIR__ . '/../../utils/JWTHandler.php';

    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $auth_header = $headers['authorization'] ?? '';

    if (!$auth_header || !preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
        throw new Exception('No token provided or invalid format', 401);
    }

    $token = $matches[1];
    $jwt = new JWTHandler();
    $decodedToken = $jwt->validateToken($token);

    if (!$decodedToken) {
        throw new Exception('Invalid token', 401);
    }

    $authenticatedMember = authenticatedLoanMember($decodedToken);
    $database = new Database();
    $db = $database->getConnection();

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            createLoanRequest($db, $authenticatedMember);
            break;
        case 'GET':
            getLoanRequests($db, $authenticatedMember);
            break;
        default:
            throw new Exception('Method not allowed', 405);
    }

} catch (Exception $e) {
    error_log("Loan request error: " . $e->getMessage());
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

function createLoanRequest($db, $authenticatedMember) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) throw new Exception('Invalid JSON data', 400);
    requireLoanMember($input['requester_coop_id'] ?? null, $authenticatedMember);
    (new LoanProductService($db))->requireApplicationTerms($input['product_version_id'] ?? null, 'member');
}

function getLoanRequests($db, $authenticatedMember) {
    $requester_coop_id = isset($_GET['requester_coop_id']) ? trim($_GET['requester_coop_id']) : null;

    if (!$requester_coop_id) {
        throw new Exception('requester_coop_id is required', 400);
    }

    requireLoanMember($requester_coop_id, $authenticatedMember);

    $query = "SELECT 
        lr.id,
        lr.requester_coop_id,
        lr.requested_amount,
        lr.monthly_repayment,
        lr.payslip_file_path,
        lr.status,
        lr.period_id,
        lr.created_at,
        lr.updated_at,
        lr.submitted_at,
        (SELECT COUNT(*) FROM guarantor_requests gr WHERE gr.loan_request_id = lr.id AND gr.status = 'approved') as approved_guarantors,
        (SELECT COUNT(*) FROM guarantor_requests gr WHERE gr.loan_request_id = lr.id AND gr.status = 'rejected') as rejected_guarantors,
        (SELECT COUNT(*) FROM guarantor_requests gr WHERE gr.loan_request_id = lr.id) as total_guarantors
    FROM loan_requests lr
    WHERE lr.requester_coop_id = :requester_coop_id
    ORDER BY lr.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute([':requester_coop_id' => $requester_coop_id]);

    $loanRequests = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $loanRequests[] = [
            'id' => intval($row['id']),
            'requester_coop_id' => $row['requester_coop_id'],
            'requested_amount' => floatval($row['requested_amount']),
            'monthly_repayment' => floatval($row['monthly_repayment']),
            'payslip_file_path' => $row['payslip_file_path'],
            'status' => $row['status'],
            'period_id' => $row['period_id'] ? intval($row['period_id']) : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'submitted_at' => $row['submitted_at'],
            'approved_guarantors' => intval($row['approved_guarantors']),
            'rejected_guarantors' => intval($row['rejected_guarantors']),
            'total_guarantors' => intval($row['total_guarantors'])
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $loanRequests
    ]);
}