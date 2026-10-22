<?php
require_once __DIR__ . '/../includes/session_helper.php';
require_once __DIR__ . '/../includes/admin_access_helper.php';
initSession();
header('Content-Type: application/json');
$accessError = findAdminAccessError($_SESSION);
if ($accessError !== null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $accessError]);
    exit;
}
http_response_code(409);
echo json_encode(['success' => false, 'message' => 'Legacy payroll deletion is unavailable because it does not reverse posted accounting entries. No records were deleted.']);
