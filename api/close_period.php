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
require_once('../libs/services/PeriodClosingProcessor.php');
try {
    $periodid = intval($_POST['periodid'] ?? 0);
    
    if ($periodid <= 0) {
        throw new Exception('Invalid period ID');
    }
    
    // Collect appropriation data
    $appropriation = [
        'surplus_amount' => moneyDecimal(moneyMinorUnits(($_POST['surplus_amount'] ?? '') === '' ? '0.00' : $_POST['surplus_amount'])),
        'dividend' => moneyDecimal(moneyMinorUnits(($_POST['dividend'] ?? '') === '' ? '0.00' : $_POST['dividend'])),
        'interest_to_members' => moneyDecimal(moneyMinorUnits(($_POST['interest_to_members'] ?? '') === '' ? '0.00' : $_POST['interest_to_members'])),
        'reserve_fund' => moneyDecimal(moneyMinorUnits(($_POST['reserve_fund'] ?? '') === '' ? '0.00' : $_POST['reserve_fund'])),
        'bonus' => moneyDecimal(moneyMinorUnits(($_POST['bonus'] ?? '') === '' ? '0.00' : $_POST['bonus'])),
        'education_fund' => moneyDecimal(moneyMinorUnits(($_POST['education_fund'] ?? '') === '' ? '0.00' : $_POST['education_fund'])),
        'honorarium' => moneyDecimal(moneyMinorUnits(($_POST['honorarium'] ?? '') === '' ? '0.00' : $_POST['honorarium'])),
        'general_reserve' => moneyDecimal(moneyMinorUnits(($_POST['general_reserve'] ?? '') === '' ? '0.00' : $_POST['general_reserve'])),
        'welfare_fund' => moneyDecimal(moneyMinorUnits(($_POST['welfare_fund'] ?? '') === '' ? '0.00' : $_POST['welfare_fund']))
    ];
    
    // Calculate retained earnings
    $totalAppropriated = 0;
    foreach ($appropriation as $key=>$amount) {
        if ($key !== 'surplus_amount') $totalAppropriated = moneyAdd($totalAppropriated,moneyMinorUnits($amount));
    }
    $appropriation['retained_earnings'] = moneyDecimal(moneyAdd(moneyMinorUnits($appropriation['surplus_amount']),-$totalAppropriated));

    // Close period
    $processor = new PeriodClosingProcessor($coop, $database);
    $result = $processor->closePeriod($periodid, $_SESSION['SESS_MEMBER_ID'], $appropriation);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'entries_created' => $result['entries_created'] ?? [],
            'message' => $result['message']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['error'],
            'issues' => $result['issues'] ?? []
        ]);
    }
    
} catch (Exception $e) {
    error_log("Period closing error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
