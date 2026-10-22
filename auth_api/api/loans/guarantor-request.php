<?php
// api/loans/guarantor-request.php
// Send guarantor requests and manage guarantor responses

// Clean any previous output and start buffering
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Disable error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(200);
    ob_end_flush();
    exit();
}

try {
    // Suppress warnings from required files to prevent HTML output
    $oldErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
    $oldDisplayErrors = ini_get('display_errors');
    ini_set('display_errors', 0);
    
    require_once __DIR__ . '/../../config/Database.php';
    require_once __DIR__ . '/../../utils/JWTHandler.php';
    require_once __DIR__ . '/../../../includes/loan_consent_helper.php';
    
    // Try multiple paths for send_notification.php
    $possiblePaths = [
        __DIR__ . '/../../onesignal/send_notification.php',  // Standard path: auth_api/api/loans -> auth_api/onesignal
        __DIR__ . '/../../../onesignal/send_notification.php', // Alternative: if structure is different
        dirname(__DIR__, 2) . '/onesignal/send_notification.php', // Using dirname() for better path resolution
    ];
    
    $notificationPath = null;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $notificationPath = $path;
            break;
        }
    }
    
    if ($notificationPath) {
        require_once $notificationPath;
    } else {
        // Log warning but don't fail - notifications are optional
    }
    
    ini_set('display_errors', $oldDisplayErrors);
    error_reporting($oldErrorReporting);

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
            sendGuarantorRequest($db, $authenticatedMember);
            break;
        case 'GET':
            getGuarantorRequests($db, $authenticatedMember);
            break;
        case 'PUT':
            respondToGuarantorRequest($db, $authenticatedMember);
            break;
        default:
            throw new Exception('Method not allowed', 405);
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    // Clean output buffer before sending error
    ob_clean();
    

    $status_code = $e->getCode();
    if (!is_int($status_code) || $status_code < 100 || $status_code > 599) {
        $status_code = 400;
    }

    http_response_code($status_code);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    ob_end_flush();
    exit();
} catch (Error $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    // Catch PHP 7+ errors (TypeError, ParseError, etc.)
    ob_clean();
    

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
    ob_end_flush();
    exit();
}

function sendGuarantorRequest($db, $authenticatedMember) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid JSON data', 400);
    }

    $loan_request_id = isset($input['loan_request_id']) ? intval($input['loan_request_id']) : 0;
    $guarantor_coop_id = isset($input['guarantor_coop_id']) ? trim($input['guarantor_coop_id']) : '';

    if (!$loan_request_id || empty($guarantor_coop_id)) {
        throw new Exception('loan_request_id and guarantor_coop_id are required', 400);
    }

    $db->beginTransaction();
    // Get loan request details
    $loanQuery = "SELECT lr.*, 
        CONCAT(e.FirstName, ' ', IFNULL(e.MiddleName, ''), ' ', e.LastName) as requester_name
    FROM loan_requests lr
    JOIN tblemployees e ON lr.requester_coop_id = e.CoopID
    WHERE lr.id = :loan_request_id FOR UPDATE";
    
    $loanStmt = $db->prepare($loanQuery);
    $loanStmt->execute([':loan_request_id' => $loan_request_id]);
    $loanRequest = $loanStmt->fetch(PDO::FETCH_ASSOC);

    if (!$loanRequest) {
        throw new Exception('Loan request not found', 404);
    }

    requireLoanMember($loanRequest['requester_coop_id'], $authenticatedMember);

    // Check if guarantor is different from requester
    if ($loanRequest['requester_coop_id'] === $guarantor_coop_id) {
        throw new Exception('Guarantor cannot be the same as the requester', 400);
    }

    // Check if guarantor exists and is active
    $guarantorQuery = "SELECT CoopID FROM tblemployees WHERE CoopID = :coop_id AND Status = 'Active'";
    $guarantorStmt = $db->prepare($guarantorQuery);
    $guarantorStmt->execute([':coop_id' => $guarantor_coop_id]);
    
    if ($guarantorStmt->rowCount() === 0) {
        throw new Exception('Guarantor not found or inactive', 404);
    }

    // Check loan request status - prevent sending if already submitted
    if (!in_array($loanRequest['status'], ['draft', 'pending_guarantors', 'partially_guaranteed', 'rejected', 'submitted', 'fully_guaranteed'], true)) {
        throw new Exception('Loan request has already been submitted and cannot be modified', 400);
    }

    // Check if guarantor request already exists with pending or approved status
    $checkQuery = "SELECT * FROM guarantor_requests
        WHERE loan_request_id = :loan_request_id AND guarantor_coop_id = :guarantor_coop_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([
        ':loan_request_id' => $loan_request_id,
        ':guarantor_coop_id' => $guarantor_coop_id
    ]);
    $existingRequest = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (in_array($loanRequest['status'], ['submitted', 'fully_guaranteed'], true) &&
        (!$existingRequest || !empty($existingRequest['responded_by_coop_id']))) {
        throw new Exception('Only legacy unverified consent may be renewed after submission', 409);
    }
    // The locked loan serializes invitations, including renewal of rejected rows.
    $countStmt = $db->prepare("SELECT COUNT(*) FROM guarantor_requests
        WHERE loan_request_id = :loan_id AND status IN ('pending', 'approved')
        AND id != :existing_id");
    $countStmt->execute([':loan_id' => $loan_request_id, ':existing_id' => $existingRequest['id'] ?? 0]);
    if ((int) $countStmt->fetchColumn() >= 2) {
        throw new Exception('Maximum of 2 guarantors allowed. Please replace an existing guarantor first.', 409);
    }
    if ($existingRequest) {
        // If already approved, cannot replace
        if ($existingRequest['status'] === 'approved' && !empty($existingRequest['responded_by_coop_id'])) {
            throw new Exception('Cannot replace an approved guarantor', 400);
        }
        // If pending, cannot resend
        if ($existingRequest['status'] === 'pending') {
            throw new Exception('Guarantor request already pending', 400);
        }
        // A rejected or unverified legacy response may receive a new invitation.
        if ($existingRequest['status'] === 'rejected' || empty($existingRequest['responded_by_coop_id'])) {
            // Preserve the previous response before renewing the invitation.
            $history = $db->prepare("INSERT INTO loan_guarantor_response_history
                (guarantor_request_id, guarantor_coop_id, response, responded_by_coop_id,
                 consent_terms_hash, terms_snapshot, response_date, response_notes)
                VALUES (:id, :guarantor, :response, :actor, :hash, :terms, :date, :notes)");
            $history->execute([
                ':id' => $existingRequest['id'], ':guarantor' => $existingRequest['guarantor_coop_id'],
                ':response' => $existingRequest['status'], ':actor' => $existingRequest['responded_by_coop_id'],
                ':hash' => $existingRequest['consent_terms_hash'],
                ':terms' => json_encode(['requested_amount' => $existingRequest['requested_amount'],
                    'monthly_repayment' => $existingRequest['monthly_repayment']], JSON_THROW_ON_ERROR),
                ':date' => $existingRequest['response_date'], ':notes' => $existingRequest['response_notes']
            ]);
            $reset = $db->prepare("UPDATE guarantor_requests SET status = 'pending',
                response_date = NULL, response_notes = NULL, responded_by_coop_id = NULL,
                consent_terms_hash = NULL, requested_amount = :amount,
                monthly_repayment = :repayment, updated_at = NOW() WHERE id = :id");
            $reset->execute([':id' => $existingRequest['id'], ':amount' => $loanRequest['requested_amount'],
                ':repayment' => $loanRequest['monthly_repayment']]);
        }
    }

    // Edge case: Check if trying to replace an approved guarantor
    // Get all guarantor requests for this loan
    $allGuarantorsQuery = "SELECT guarantor_coop_id, status FROM guarantor_requests 
        WHERE loan_request_id = :loan_request_id AND status = 'approved'";
    $allGuarantorsStmt = $db->prepare($allGuarantorsQuery);
    $allGuarantorsStmt->execute([':loan_request_id' => $loan_request_id]);
    $approvedGuarantors = $allGuarantorsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($approvedGuarantors as $approved) {
        if ($approved['guarantor_coop_id'] === $guarantor_coop_id) {
            throw new Exception('Cannot replace an approved guarantor', 400);
        }
    }

    if (!$existingRequest) {
    // Insert guarantor request
    
    $insertQuery = "INSERT INTO guarantor_requests 
        (loan_request_id, guarantor_coop_id, requester_name, requested_amount, monthly_repayment, status)
        VALUES (:loan_request_id, :guarantor_coop_id, :requester_name, :requested_amount, :monthly_repayment, 'pending')";

    $insertStmt = $db->prepare($insertQuery);
    $insertStmt->execute([
        ':loan_request_id' => $loan_request_id,
        ':guarantor_coop_id' => $guarantor_coop_id,  // IMPORTANT: This is the guarantor's CoopID
        ':requester_name' => $loanRequest['requester_name'],
        ':requested_amount' => $loanRequest['requested_amount'],
        ':monthly_repayment' => $loanRequest['monthly_repayment']
    ]);

    $guarantorRequestId = $db->lastInsertId();
    } else {
        $guarantorRequestId = $existingRequest['id'];
    }

    // Update loan request status
    $statusResult = updateLoanRequestStatus($db, $loan_request_id);

    $db->commit();

    if ($statusResult['limit_exceeded'] !== null) {
        sendLimitExceededNotification($db, ...$statusResult['limit_exceeded']);
    }

    // Send notification to guarantor (NOT the requester)
    sendGuarantorNotification($db, $guarantor_coop_id, $loanRequest['requester_name'], $loanRequest['requested_amount']);

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Guarantor request sent successfully',
        'data' => [
            'id' => $guarantorRequestId,
            'loan_request_id' => $loan_request_id,
            'guarantor_coop_id' => $guarantor_coop_id,
            'status' => 'pending'
        ]
    ]);
    ob_end_flush();
    exit();
}

function respondToGuarantorRequest($db, $authenticatedMember) {
    $input = json_decode(file_get_contents('php://input'), true);
    $requestId = intval($input['guarantor_request_id'] ?? 0);
    $response = $input['response'] ?? '';
    if (!$requestId || !in_array($response, ['approved', 'rejected'], true)) {
        throw new Exception('A guarantor request and approved/rejected response are required', 400);
    }
    $stmt = $db->prepare('SELECT * FROM guarantor_requests WHERE id = :id');
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        throw new Exception('Guarantor request not found', 404);
    }
    requireLoanMember($request['guarantor_coop_id'], $authenticatedMember);

    $db->beginTransaction();
    try {
        // Use the same loan-then-consent lock order as loan approval.
        $loanStmt = $db->prepare('SELECT * FROM loan_requests WHERE id = :id FOR UPDATE');
        $loanStmt->execute([':id' => $request['loan_request_id']]);
        $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
        if (!$loan || !in_array($loan['status'], ['draft', 'pending_guarantors', 'partially_guaranteed'], true)) {
            throw new Exception('This loan no longer accepts guarantor responses', 409);
        }
        $stmt = $db->prepare('SELECT * FROM guarantor_requests WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new Exception('Guarantor request no longer exists', 409);
        }
        requireLoanMember($request['guarantor_coop_id'], $authenticatedMember);
        if ($request['status'] !== 'pending') {
            throw new Exception('A response has already been recorded', 409);
        }
        if ((string) $loan['requester_coop_id'] === $authenticatedMember) {
            throw new Exception('You cannot guarantee your own loan', 409);
        }
        if (loanAmountMinorUnits($request['requested_amount']) !== loanAmountMinorUnits($loan['requested_amount']) ||
            loanAmountMinorUnits($request['monthly_repayment']) !== loanAmountMinorUnits($loan['monthly_repayment'])) {
            throw new Exception('Loan terms have changed; request a new invitation', 409);
        }
        $memberStmt = $db->prepare("SELECT Status FROM tblemployees WHERE CoopID = :id FOR UPDATE");
        $memberStmt->execute([':id' => $authenticatedMember]);
        if ($memberStmt->fetchColumn() !== 'Active') {
            throw new Exception('Only active members can respond as guarantors', 403);
        }
        $updateStmt = $db->prepare('UPDATE guarantor_requests
            SET status = :status, response_date = NOW(), response_notes = :notes, updated_at = NOW(),
                responded_by_coop_id = :actor, consent_terms_hash = :hash
            WHERE id = :id');
        $updateStmt->execute([
            ':status' => $response,
            ':actor' => $authenticatedMember,
            ':hash' => loanConsentTermsHash($loan),
            ':notes' => isset($input['response_notes']) ? trim((string) $input['response_notes']) : null,
            ':id' => $requestId
        ]);
        $history = $db->prepare('INSERT INTO loan_guarantor_response_history
            (guarantor_request_id, guarantor_coop_id, response, responded_by_coop_id,
             consent_terms_hash, terms_snapshot, response_date, response_notes)
            VALUES (:id, :guarantor, :response, :actor, :hash, :terms, NOW(), :notes)');
        $history->execute([
            ':id' => $requestId, ':guarantor' => $authenticatedMember, ':response' => $response,
            ':actor' => $authenticatedMember, ':hash' => loanConsentTermsHash($loan),
            ':terms' => json_encode(['borrower' => $loan['requester_coop_id'],
                'requested_amount' => $loan['requested_amount'],
                'monthly_repayment' => $loan['monthly_repayment']], JSON_THROW_ON_ERROR),
            ':notes' => isset($input['response_notes']) ? trim((string) $input['response_notes']) : null
        ]);
        $statusResult = updateLoanRequestStatus($db, $request['loan_request_id']);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    if ($statusResult['limit_exceeded'] !== null) {
        sendLimitExceededNotification($db, ...$statusResult['limit_exceeded']);
    }
    sendRequesterNotification($db, $loan['requester_coop_id'], $request['requester_name'], $response);
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Guarantor response recorded',
        'data' => ['guarantor_request_id' => $requestId, 'response' => $response,
            'loan_status' => $statusResult['status']]
    ]);
    ob_end_flush();
    exit();
}

function getGuarantorRequests($db, $authenticatedMember) {
    $guarantor_coop_id = isset($_GET['guarantor_coop_id']) ? trim($_GET['guarantor_coop_id']) : null;
    $loan_request_id = isset($_GET['loan_request_id']) ? intval($_GET['loan_request_id']) : null;


    if (!$guarantor_coop_id && !$loan_request_id) {
        throw new Exception('Either guarantor_coop_id or loan_request_id is required', 400);
    }

    $query = "SELECT 
        gr.id,
        gr.loan_request_id,
        gr.guarantor_coop_id,
        gr.requester_name,
        gr.requested_amount,
        gr.monthly_repayment,
        gr.status,
        gr.response_date,
        gr.response_notes,
        gr.created_at,
        gr.updated_at,
        lr.requester_coop_id,
        lr.status as loan_status
    FROM guarantor_requests gr
    JOIN loan_requests lr ON gr.loan_request_id = lr.id";

    $params = [];
    if ($guarantor_coop_id) {
        requireLoanMember($guarantor_coop_id, $authenticatedMember);
        $query .= " WHERE gr.guarantor_coop_id = :guarantor_coop_id";
        $params[':guarantor_coop_id'] = $guarantor_coop_id;
    } else {
        $query .= " WHERE gr.loan_request_id = :loan_request_id AND lr.requester_coop_id = :authenticated_member";
        $params[':authenticated_member'] = $authenticatedMember;
        $params[':loan_request_id'] = $loan_request_id;
    }

    $query .= " ORDER BY gr.created_at DESC";


    $stmt = $db->prepare($query);
    $stmt->execute($params);

    $guarantorRequests = [];
    $rowCount = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rowCount++;
        $guarantorRequests[] = [
            'id' => intval($row['id']),
            'loan_request_id' => intval($row['loan_request_id']),
            'guarantor_coop_id' => $row['guarantor_coop_id'],
            'requester_name' => $row['requester_name'],
            'requested_amount' => floatval($row['requested_amount']),
            'monthly_repayment' => floatval($row['monthly_repayment']),
            'status' => $row['status'],
            'response_date' => $row['response_date'],
            'response_notes' => $row['response_notes'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'loan_status' => $row['loan_status']
        ];
    }
    

    ob_clean();
    echo json_encode([
        'success' => true,
        'data' => $guarantorRequests
    ]);
    ob_end_flush();
    exit();
}

function updateLoanRequestStatus($db, $loanRequestId) {
    return refreshLoanEvidenceStatus($db, $loanRequestId);
}

function sendGuarantorNotification($db, $guarantorCoopId, $requesterName, $requestedAmount) {
    try {
        
        // Get guarantor's email, mobile, and OneSignal ID
        // Try both oneSignal table and tblemployees.onesignal_id field
        $memberQuery = "SELECT CoopID, EmailAddress, MobileNumber, onesignal_id FROM tblemployees WHERE CoopID = :coop_id";
        $memberStmt = $db->prepare($memberQuery);
        $memberStmt->execute([':coop_id' => $guarantorCoopId]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            return;
        }


        $message = "You have been requested to be a guarantor for a loan request from {$requesterName} for ₦" . number_format($requestedAmount, 2);
        
        // Save in-app notification FOR THE GUARANTOR (not the requester)
        $notifQuery = "INSERT INTO notifications (coop_id, title, message, status) 
            VALUES (:coop_id, 'Guarantor Request', :message, 'unread')";
        $notifStmt = $db->prepare($notifQuery);
        $notifStmt->execute([
            ':coop_id' => $guarantorCoopId,  // IMPORTANT: This should be the guarantor's CoopID
            ':message' => $message
        ]);
        

        // Try to get OneSignal player_id from oneSignal table first
        $playerId = null;
        $oneSignalQuery = "SELECT player_id FROM oneSignal WHERE coop_id = :coop_id LIMIT 1";
        $oneSignalStmt = $db->prepare($oneSignalQuery);
        $oneSignalStmt->execute([':coop_id' => $guarantorCoopId]);  // IMPORTANT: Using guarantor's CoopID
        $oneSignal = $oneSignalStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($oneSignal && !empty($oneSignal['player_id'])) {
            $playerId = $oneSignal['player_id'];
        } elseif (!empty($member['onesignal_id'])) {
            // Fallback to onesignal_id from tblemployees table
            $playerId = $member['onesignal_id'];
        }

        // Send push notification via OneSignal if player_id is available and function exists
        if ($playerId && function_exists('sendNotificationToDevice')) {
            sendNotificationToDevice($playerId, 'Guarantor Request', 'Sign in to review a guarantor invitation.');
        } else {
            if (!$playerId) {
            }
            if (!function_exists('sendNotificationToDevice')) {
            }
        }

    } catch (Exception $e) {
    }
}

function sendRequesterNotification($db, $requesterCoopId, $requesterName, $response) {
    try {
        $message = $response === 'approved' 
            ? "A guarantor has approved your loan request for {$requesterName}"
            : "A guarantor has rejected your loan request for {$requesterName}. You may need to select another guarantor.";
        
        // Save in-app notification
        $notifQuery = "INSERT INTO notifications (coop_id, title, message, status) 
            VALUES (:coop_id, 'Loan Request Update', :message, 'unread')";
        $notifStmt = $db->prepare($notifQuery);
        $notifStmt->execute([
            ':coop_id' => $requesterCoopId,
            ':message' => $message
        ]);

        // Try to get OneSignal player_id from oneSignal table first
        $playerId = null;
        $oneSignalQuery = "SELECT player_id FROM oneSignal WHERE coop_id = :coop_id LIMIT 1";
        $oneSignalStmt = $db->prepare($oneSignalQuery);
        $oneSignalStmt->execute([':coop_id' => $requesterCoopId]);
        $oneSignal = $oneSignalStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($oneSignal && !empty($oneSignal['player_id'])) {
            $playerId = $oneSignal['player_id'];
        } else {
            // Fallback to onesignal_id from tblemployees table
            $memberQuery = "SELECT onesignal_id FROM tblemployees WHERE CoopID = :coop_id LIMIT 1";
            $memberStmt = $db->prepare($memberQuery);
            $memberStmt->execute([':coop_id' => $requesterCoopId]);
            $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($member && !empty($member['onesignal_id'])) {
                $playerId = $member['onesignal_id'];
            }
        }

        // Send push notification via OneSignal if player_id is available and function exists
        if ($playerId && function_exists('sendNotificationToDevice')) {
            sendNotificationToDevice($playerId, 'Loan Request Update', 'Sign in to view an update on your loan request.');
        } else {
            if (!$playerId) {
            }
        }

    } catch (Exception $e) {
    }
}

function sendLimitExceededNotification($db, $requesterCoopId, $requestedAmount, $limitAmount, $currentTotal) {
    try {
        // Get requester's details
        $memberQuery = "SELECT CoopID, EmailAddress, MobileNumber, onesignal_id FROM tblemployees WHERE CoopID = :coop_id";
        $memberStmt = $db->prepare($memberQuery);
        $memberStmt->execute([':coop_id' => $requesterCoopId]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            return;
        }

        $notificationTitle = 'Loan Request Rejected';
        $notificationMessage = "Your loan request of ₦" . number_format($requestedAmount, 2) . 
            " has been rejected because it would exceed the monthly loan limit of ₦" . number_format($limitAmount, 2) . 
            ". Current total loans: ₦" . number_format($currentTotal, 2);
        
        // Save in-app notification
        $notifQuery = "INSERT INTO notifications (coop_id, title, message, status) 
            VALUES (:coop_id, :title, :message, 'unread')";
        $notifStmt = $db->prepare($notifQuery);
        $notifStmt->execute([
            ':coop_id' => $requesterCoopId,
            ':title' => $notificationTitle,
            ':message' => $notificationMessage
        ]);

        // Get OneSignal device ID
        $deviceId = null;
        
        // Try to get from oneSignal table first
        $oneSignalQuery = "SELECT player_id FROM oneSignal WHERE coop_id = :coop_id LIMIT 1";
        $oneSignalStmt = $db->prepare($oneSignalQuery);
        $oneSignalStmt->execute([':coop_id' => $requesterCoopId]);
        $oneSignal = $oneSignalStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($oneSignal && !empty($oneSignal['player_id'])) {
            $deviceId = $oneSignal['player_id'];
        } elseif (!empty($member['onesignal_id'])) {
            $deviceId = $member['onesignal_id'];
        }

        // Send push notification if device ID is available
        if ($deviceId && function_exists('sendNotificationToDevice')) {
            sendNotificationToDevice($deviceId, 'Loan request update', 'Sign in to view the decision on your loan request.');
        }
    } catch (Exception $e) {
    }
}
