<?php
// admin/handle_approval.php

header('Content-Type: application/json');
session_start();

require_once '../config/Database.php';
require_once '../utils/JWTHandler.php';
require_once __DIR__ . '/../../includes/member_identity_helper.php';
require_once __DIR__ . '/../../includes/admin_access_helper.php';

require_once '../utils/EmailService.php';


// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required', 405);
    if (findAdminMutationOriginError($_SERVER) !== null) throw new Exception('Invalid request origin', 403);
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['staff_id']) || !isset($data['action'])) {
        throw new Exception('Invalid request data');
    }

    $database = new Database();
    $db = $database->getConnection();
    $reviewer = $db->prepare('SELECT u.staff_id FROM tbl_users u JOIN employee e ON e.staff_id = u.staff_id WHERE u.id = ? AND e.is_admin = 1');
    $reviewer->execute([$_SESSION['admin_id']]);
    $admin_staff_id = $reviewer->fetchColumn();
    if (!$admin_staff_id) throw new Exception('Administrator privileges required', 403);
    if ((string)$admin_staff_id === (string)$data['staff_id']) throw new Exception('You cannot review your own changes', 403);
    if (!in_array($data['action'], ['approve', 'reject'], true)) throw new Exception('Invalid review action', 400);
    $db->beginTransaction();
    $lock = $db->prepare('SELECT staff_id FROM employee WHERE staff_id = ? FOR UPDATE');
    $lock->execute([$data['staff_id']]);
    if ($lock->fetchColumn() === false) throw new Exception('Profile not found', 404);

    $staff_id = $data['staff_id'];
    $action = $data['action'];
    $reason = $data['reason'] ?? null;
    $admin_id = $_SESSION['admin_id'];

    if ($action === 'approve') {
        // Get profile changes
        $profile_stmt = $db->prepare("
            SELECT field_name, new_value 
            FROM pending_profile_changes 
            WHERE staff_id = ? AND status = 'pending'
        ");
        $profile_stmt->execute([$staff_id]);
        $changes = $profile_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Apply profile changes
        foreach ($changes as $change) {
            $field = $change['field_name'];
            $value = $change['new_value'];
            validateMemberProfileChanges([$field => $value]);

            $update_stmt = $db->prepare("
                UPDATE employee 
                SET $field = ?, 
                    last_approved_at = CURRENT_TIMESTAMP,
                    approved_by = ?
                WHERE staff_id = ?
            ");
            $update_stmt->execute([$value, $admin_staff_id, $staff_id]);
        }

        // Handle qualification changes
        $qual_stmt = $db->prepare("
            SELECT * FROM pending_qualification_changes 
            WHERE staff_id = ? AND status = 'pending'
        ");
        $qual_stmt->execute([$staff_id]);
        $qual_changes = $qual_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($qual_changes as $change) {
            switch ($change['change_type']) {
                case 'add':
                    $insert_stmt = $db->prepare("
                        INSERT INTO staff_qualification 
                        (staff_id, qua_id, field, institution, year_obtained)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $insert_stmt->execute([
                        $staff_id,
                        $change['qua_id'],
                        $change['field'],
                        $change['institution'],
                        $change['year_obtained']
                    ]);
                    break;

                case 'edit':
                    $update_stmt = $db->prepare("
                        UPDATE staff_qualification
                        SET qua_id = ?, field = ?, institution = ?, year_obtained = ?
                        WHERE id = ? AND staff_id = ?
                    ");
                    $update_stmt->execute([
                        $change['qua_id'],
                        $change['field'],
                        $change['institution'],
                        $change['year_obtained'],
                        $change['original_qualification_id'], $staff_id
                    ]);
                    break;

                case 'delete':
                    $delete_stmt = $db->prepare("
                        DELETE FROM staff_qualification 
                        WHERE id = ? AND staff_id = ?
                    ");
                    $delete_stmt->execute([$change['original_qualification_id'], $staff_id]);
                    break;
            }
        }

        // Update status of pending changes
        $update_status = $db->prepare("
            UPDATE pending_profile_changes 
            SET status = 'approved', 
                approved_by = ?,
                approved_at = CURRENT_TIMESTAMP
            WHERE staff_id = ? AND status = 'pending'
        ");
        $update_status->execute([$admin_staff_id, $staff_id]);

        $update_qual_status = $db->prepare("
            UPDATE pending_qualification_changes 
            SET status = 'approved', 
                approved_by = ?,
                approved_at = CURRENT_TIMESTAMP
            WHERE staff_id = ? AND status = 'pending'
        ");
        $update_qual_status->execute([$admin_staff_id, $staff_id]);

    } elseif ($action === 'reject') {
        if (empty($reason)) {
            throw new Exception('Rejection reason is required');
        }

        // Update status of pending changes
        $update_status = $db->prepare("
            UPDATE pending_profile_changes 
            SET status = 'rejected', 
                approved_by = ?,
                approved_at = CURRENT_TIMESTAMP,
                rejection_reason = ?
            WHERE staff_id = ? AND status = 'pending'
        ");
        $update_status->execute([$admin_staff_id, $reason, $staff_id]);

        $update_qual_status = $db->prepare("
            UPDATE pending_qualification_changes 
            SET status = 'rejected', 
                approved_by = ?,
                approved_at = CURRENT_TIMESTAMP,
                rejection_reason = ?
            WHERE staff_id = ? AND status = 'pending'
        ");
        $update_qual_status->execute([$admin_staff_id, $reason, $staff_id]);
    }

    // Reset pending changes flag
    $reset_pending = $db->prepare("
        UPDATE employee 
        SET has_pending_changes = false 
        WHERE staff_id = ?
    ");
    $reset_pending->execute([$staff_id]);

    $db->commit();


    // Get staff email
    $staff_stmt = $db->prepare("SELECT EMAIL, NAME FROM employee WHERE staff_id = ?");
    $staff_stmt->execute([$staff_id]);
    $staff_info = $staff_stmt->fetch(PDO::FETCH_ASSOC);

    $notificationSent = false;
    try {
    $emailService = new EmailService($db);
    if ($action === 'approve') {
        $notificationSent = $emailService->sendApprovalNotification(
            $staff_info['EMAIL'],
            $staff_info['NAME'],
            $changes
        );
    } else {
        $notificationSent = $emailService->sendRejectionNotification(
            $staff_info['EMAIL'],
            $staff_info['NAME'],
            $reason
        );
    }
    } catch (Exception $notificationError) {
        error_log('Profile review committed; notification delivery failed');
    }


    echo json_encode([
        'success' => true,
        'notification_sent' => $notificationSent === true,
        'message' => $action === 'approve' ? 'Changes approved successfully' : 'Changes rejected successfully'
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(in_array($e->getCode(), [400,401,403,404,405], true) ? $e->getCode() : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
