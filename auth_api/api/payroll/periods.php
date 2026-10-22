<?php
// api/payroll/periods.php

// Disable output buffering
if (ob_get_level()) ob_end_clean();

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');
header('Access-Control-Max-Age: 3600');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('HTTP/1.1 200 OK');
    exit();
}

// Set JSON content type
header('Content-Type: application/json; charset=UTF-8');

try {
    // Log request details

    // Get authorization header
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);

    if (!isset($headers['authorization'])) {
        throw new Exception('Authorization header missing', 401);
    }

    $auth_header = $headers['authorization'];
    if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        throw new Exception('Invalid authorization format', 401);
    }

    $token = $matches[1];

    // Include required files
    require_once '../../config/Database.php';
    require_once '../../utils/JWTHandler.php';

    // Validate token
    $jwt = new JWTHandler();
    $user_id = $jwt->validateToken($token);

    if (!$user_id) {
        throw new Exception('Invalid token', 401);
    }


    // Get database connection
    $database = new Database();
    $db = $database->getConnection();

    // Prepare query
    $query = "SELECT periodId, CONCAT(description,' - ', periodYear) as description 
              FROM payperiods 
              WHERE active = 0 
              ORDER BY periodId DESC";

    $stmt = $db->prepare($query);
    $stmt->execute();

    // Fetch results
    $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare response
    $response = [
        'success' => true,
        'data' => $periods
    ];

    // Make sure we have no output before this point
    if (ob_get_length()) if (ob_get_level()) ob_clean();

    // Send response
    echo json_encode($response);
    exit();

} catch (Exception $e) {

    // Make sure we have no output before this point
    if (ob_get_length()) if (ob_get_level()) ob_clean();

    // Send error response
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit();
}
?>