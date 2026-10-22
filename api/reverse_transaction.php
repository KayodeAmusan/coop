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
    $memberid = intval($_POST['memberid'] ?? 0);
    $periodid = intval($_POST['periodid'] ?? 0);
    
    if ($memberid <= 0 || $periodid <= 0) {
        throw new Exception('Invalid member or period ID');
    }
    
    $engine = new AccountingEngine($coop, $database);
    
    // Find all journal entries for this member/period
    $sql = "SELECT id, entry_number, source_document, description, total_amount
            FROM coop_journal_entries
            WHERE periodid = ? 
            AND (source_document LIKE ? OR source_document LIKE ?)
            AND status = 'posted' AND is_reversed = FALSE
            ORDER BY id ASC";
    
    $stmt = $coop->prepare($sql);
    $contrib_pattern = "CONTRIB-{$memberid}-%";
    $loan_pattern = "LOAN-{$memberid}-%";
    $stmt->execute([$periodid, $contrib_pattern, $loan_pattern]);
    
    $entries_to_reverse = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $entries_to_reverse[] = $row;
    }
    
    if (empty($entries_to_reverse)) {
        echo json_encode([
            'success' => true,
            'message' => 'No accounting entries found to reverse',
            'entries_reversed' => 0
        ]);
        exit;
    }
    
    $coop->beginTransaction();
    // Reverse each entry
    $reversed_count = 0;
    $errors = [];
    
    foreach ($entries_to_reverse as $entry) {
        $result = $engine->reverseEntry($entry['id'], $_SESSION['SESS_MEMBER_ID'], "Reversal for transaction correction");
        
        if ($result['success']) {
            $reversed_count++;
        } else {
            throw new RuntimeException("Reversal failed: " . $result['error']);
        }
    }
    
    $coop->commit();
    if ($reversed_count == count($entries_to_reverse)) {
        echo json_encode([
            'success' => true,
            'message' => "{$reversed_count} journal entries reversed successfully",
            'entries_reversed' => $reversed_count
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => "Only {$reversed_count} of " . count($entries_to_reverse) . " entries reversed. Errors: " . implode('; ', $errors)
        ]);
    }
    
} catch (Throwable $e) {
    if (isset($coop) && $coop->inTransaction()) $coop->rollBack();
    error_log("Transaction reversal error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
