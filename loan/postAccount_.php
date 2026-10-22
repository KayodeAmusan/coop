<?php
http_response_code(409);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Legacy batch posting is unavailable pending canonical verified disbursement controls.']);
exit;
