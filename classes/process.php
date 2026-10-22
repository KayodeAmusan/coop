<?php
require_once __DIR__ . '/../includes/session_helper.php';
initSession();
require_once __DIR__ . '/../includes/admin_access_helper.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$accessError = findAdminAccessError($_SESSION);
if ($accessError !== null) {
    http_response_code(403);
    echo "ERROR: $accessError\n";
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo "ERROR: Use POST to process deductions.\n";
    exit;
}
$originError = findAdminMutationOriginError($_SERVER);
if ($originError !== null) {
    http_response_code(403);
    echo "ERROR: $originError\n";
    exit;
}
$periodInput = $_POST['PeriodID'] ?? null;
$memberInput = $_POST['member_id'] ?? null;
if ((!is_string($periodInput) && !is_int($periodInput)) ||
    !preg_match('/\A[1-9][0-9]{0,9}\z/', (string)$periodInput) || (int)$periodInput > 2147483647 ||
    (!is_string($memberInput) && !is_int($memberInput)) || trim((string)$memberInput) === '' ||
    strlen(trim((string)$memberInput)) > 255 || preg_match('/[\x00-\x1F\x7F]/', (string)$memberInput)) {
    http_response_code(400);
    echo "ERROR: Select a valid payroll period and member selection.\n";
    exit;
}
$periodID = (int)$periodInput;
$memberID = trim((string)$memberInput);
require_once __DIR__ . '/../Connections/coop.php';
require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/../libs/services/AccountingEngine.php';
require_once __DIR__ . '/../includes/payroll_helper.php';

use App\Services\NotificationService;

// Configuration
ini_set('max_execution_time', 0);

// Initialize services
try {
    $notificationService = new NotificationService($coop);
    $accountingEngine = new AccountingEngine($coop, $database);
} catch (Throwable $e) {
    error_log("Failed to initialize services: " . $e->getMessage());
    exit;
}

include_once('model.php');

// Start transaction
try {
    $coop->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $coop->beginTransaction();
    lockPayrollRun($coop, $periodID);
    payrollHoldingConfiguration($coop);
    $notificationMembers = [];

    // Fetch global settings
    // mysqli_select_db($coop, $database); // Not needed for PDO as DB is selected in DSN
    $settingsQuery = "SELECT setting_id, `value` FROM tbl_globa_settings WHERE setting_id IN (1, 3, 4, 5, 7, 8, 9)";
    $stmt = $coop->query($settingsQuery);
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_id']] = $row['value'];
    }

    // Validate settings
    $requiredSettings = [1, 3, 4, 5, 7, 8, 9];
    foreach ($requiredSettings as $id) {
        if (!isset($settings[$id])) {
            error_log("Missing global setting ID: $id");
            $coop->rollBack();
            echo "ERROR: Missing global settings. Processing aborted.\n";
            exit;
        }
    }

    // Extract settings
    $title = $settings[1];
    $savingsRate = payrollRate($settings[4]);
    $sharesRate = payrollRate($settings[3]);
    $interestRate = payrollRate($settings[5]);
    $entryFee = moneyMinorUnits($settings[7]);
    $devLevy = moneyMinorUnits($settings[8]);
    $stationery = moneyMinorUnits($settings[9]);

    // Validate savings and shares rates
    if ($savingsRate < 0 || $sharesRate < 0 || ($savingsRate + $sharesRate) > 1000000) {
        error_log("Invalid savingsRate ($savingsRate) or sharesRate ($sharesRate) for period: $periodID");
        $coop->rollBack();
        echo "ERROR: Invalid savings or shares rate configuration.\n";
        exit;
    }

    $memberQuery = "SELECT * FROM tblemployees WHERE `Status` = 'Active'";
    $params = [];
    // Fix: Don't cast to int, as that converts alphanumeric IDs (like COOP-001) to 0
    if (!in_array($memberID, ['0', '-1'], true)) {
        $memberQuery .= " AND CoopID = ?";
        $params[] = $memberID;
    }

    $memberQuery .= " ORDER BY CoopID FOR UPDATE";

    // try { // Inner try not needed if we catch PDOException at top level or handle specifically
        $stmt = $coop->prepare($memberQuery);
        $stmt->execute($params);
        $totalRows = $stmt->rowCount();

        // Initialize progress display
        echo "PROGRESS_DATA: Starting processing... - 0%\n";

        if ($totalRows > 0) {
            $i = 1;
            while ($member = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isTransactionCompleted($coop, $member['CoopID'], $periodID)) {
                    continue;
                }

                $balances = calculateBalances($coop, $member['CoopID'], $periodID);
                $contri = $balances['contri'];

                if (!hasEntryFeePaid($coop, $member['CoopID'])) {
                    $contri = processEntryFee($coop, $member['CoopID'], $periodID, $contri, $entryFee);
                }
                processPendingCommodity($coop, $member['CoopID'], $periodID);

                if ($contri > 0) {
                    $contri = processDevLevy($coop, $member['CoopID'], $periodID, $contri, $devLevy);
                    if (hasPendingLoan($coop, $member['CoopID'], $periodID)) {
                        $contri = processStationery($coop, $member['CoopID'], $periodID, $contri, $stationery);
                    }
                }

                // Handle all balance scenarios
                if ($contri > 0 && $balances['cb'] == 0 && $balances['lb'] == 0) {
                    processSavingsAndShares($coop, $member['CoopID'], $periodID, $contri, $savingsRate, $sharesRate);
                }
                
                if ($balances['cb'] > 0 && $balances['lb'] > 0) {
                    $contri = processCommodityRepayment($coop, $member['CoopID'], $periodID, $contri, $balances['cb']);
                    $contri = processLoanRepayment($coop, $member['CoopID'], $periodID, $contri, $balances['lb'], $interestRate);
                    if ($contri > 0) {
                        processSavingsAndShares($coop, $member['CoopID'], $periodID, $contri, $savingsRate, $sharesRate);
                    }
                }
                if ($balances['cb'] == 0 && $balances['lb'] > 0) {
                    $contri = processLoanRepayment($coop, $member['CoopID'], $periodID, $contri, $balances['lb'], $interestRate);
                    if ($contri > 0) {
                        processSavingsAndShares($coop, $member['CoopID'], $periodID, $contri, $savingsRate, $sharesRate);
                    }
                }
                if ($contri > 0 && $balances['cb'] > 0) {
                    $contri = processCommodityRepayment($coop, $member['CoopID'], $periodID, $contri, $balances['cb']);
                    if ($contri > 0) {
                        processSavingsAndShares($coop, $member['CoopID'], $periodID, $contri, $savingsRate, $sharesRate);
                    }
                }

                error_log("coop id: {$member['CoopID']} contri: $contri commodity_balance: {$balances['cb']} loan_balance: {$balances['lb']} period: {$periodID}");

                processPendingLoans($coop, $member['CoopID'], $periodID);
                processLoanSavings($coop, $member['CoopID'], $periodID);

                createMemberJournalEntry($accountingEngine, $coop, $member['CoopID'], $periodID);
                $notificationMembers[] = $member['CoopID'];

                updateProgress($i, $totalRows, $member['CoopID']);
                $i++;
            }


            
            // --- Validation Checks ---
            // 1. Grand Total Deductions Validation
            $grandTotalSql = "SELECT SUM(COALESCE(sharesAmount,0) + COALESCE(savingsAmount,0) + COALESCE(InterestPaid,0) + COALESCE(DevLevy,0) + COALESCE(Stationery,0) + COALESCE(EntryFee,0) + COALESCE(loanRepayment,0) + COALESCE(CommodityRepayment,0))
                             FROM tbl_mastertransact WHERE TransactionPeriod = ?";
            $stmtGT = $coop->prepare($grandTotalSql);
            $stmtGT->execute([$periodID]);
            $processedTotal = moneyMinorUnits($stmtGT->fetchColumn() ?: '0');
            $stmtHeld = $coop->prepare('SELECT COALESCE(SUM(amount), 0) FROM coop_payroll_unallocated WHERE period_id = ?');
            $stmtHeld->execute([$periodID]);
            $processedTotal = moneyAdd($processedTotal, moneyMinorUnits($stmtHeld->fetchColumn()));

            $expectedTotalSql = "SELECT (
                (SELECT COALESCE(SUM(mc.MonthlyContribution), 0) FROM tbl_monthlycontribution mc INNER JOIN tblemployees e ON mc.coopID = e.CoopID WHERE mc.period = ? AND e.Status = 'Active') +
                (SELECT COALESCE(SUM(ls.Amount), 0) FROM tbl_loansavings ls INNER JOIN tblemployees e ON ls.COOPID = e.CoopID WHERE ls.period = ? AND e.Status = 'Active')
            ) as total_expected";
            $stmtET = $coop->prepare($expectedTotalSql);
            $stmtET->execute([$periodID, $periodID]);
            $expectedTotal = moneyMinorUnits($stmtET->fetchColumn());

            // 2. Loan Additions Validation
            $loanProcessedSql = "SELECT COALESCE(SUM(COALESCE(loan,0) + COALESCE(capitalized_interest,0)), 0) FROM tbl_mastertransact WHERE TransactionPeriod = ?";
            $stmtLP = $coop->prepare($loanProcessedSql);
            $stmtLP->execute([$periodID]);
            $loanProcessed = moneyMinorUnits($stmtLP->fetchColumn());

            $loanExpectedSql = "SELECT COALESCE(SUM(LoanAmount), 0) FROM tbl_loans WHERE LoanPeriod = ?";
            $stmtLE = $coop->prepare($loanExpectedSql);
            $stmtLE->execute([$periodID]);
            $loanExpected = moneyMinorUnits($stmtLE->fetchColumn());

            $diff = abs($processedTotal - $expectedTotal);
            $loanDiff = abs($loanProcessed - $loanExpected);
            
            error_log("Validation Results for Period $periodID: Deductions Diff: $diff, Loan Diff: $loanDiff");
            
            $validationStatus = ($diff === 0 && $loanDiff === 0) ? "SUCCESS" : "WARNING (Discrepancy detected)";
            if (in_array($memberID, ['0', '-1'], true) && ($diff !== 0 || $loanDiff !== 0)) {
                throw new RuntimeException('Payroll reconciliation failed; deductions or loan totals differ');
            }
            $coop->commit();
            foreach ($notificationMembers as $notificationMember) {
                try { $notificationService->sendTransactionNotification($notificationMember, $periodID); }
                catch (Throwable $e) { error_log('Payroll notification failed: ' . $e->getMessage()); }
            }
            echo "PROGRESS_DATA: Validation: $validationStatus - 100%\n";
            
            echo "VALIDATION_RESULTS:\n";
            echo "Deductions: Processed = N" . number_format($processedTotal / 100, 2) . ", Expected = N" . number_format($expectedTotal / 100, 2) . "\n";
            echo "Loans: Processed = N" . number_format($loanProcessed / 100, 2) . ", Expected = N" . number_format($loanExpected / 100, 2) . "\n";
            echo "VALIDATION_STATUS: $validationStatus\n";
            // -------------------------

            echo "PROGRESS_DATA: Process completed successfully for " . ($i - 1) . " employees - 100%\n";
            echo "COMPLETION: SUCCESS\n";
        } else {
            echo "ERROR: No active employees found for the provided criteria.\n";
            // If no employees, we might still want to commit or maybe rollback? 
            // Original code didn't commit if totalRows was 0? Wait, original code commit was inside `if ($totalRows > 0)` block.
            // But if we rollback here, it's fine as nothing was done.
            $coop->rollBack();
        }

       // mysqli_stmt_close($stmt); // Not needed
    // } catch (Throwable $e) { // Caught by outer block
} catch (Throwable $e) {
    if ($coop->inTransaction()) {
        $coop->rollBack();
    }
    error_log("Transaction failed: " . $e->getMessage());
    echo "ERROR: Error during processing. No transactions were applied. Details: " . $e->getMessage() . "\n";
}

// Helper Functions
function isTransactionCompleted($coop, $coopID, $periodID) {
    $query = "SELECT COUNT(*) FROM tbl_mastertransact WHERE CoopID = ? AND TransactionPeriod = ?";
    $stmt = $coop->prepare($query);
    $stmt->execute([$coopID, $periodID]);
    $count = $stmt->fetchColumn();
    return $count > 0; // Consider any transaction as completed
}

function calculateBalances($coop, $coopID, $period) {
    $queries = [
        'loan' => "SELECT COALESCE(SUM(LoanAmount), 0) FROM tbl_loans WHERE CoopID = ? AND loanperiod <= ?",
        'loanRepay' => "SELECT COALESCE(SUM(Repayment), 0) FROM tbl_loanrepayment WHERE CoopID = ? AND loanrepaymentPeriod <= ?",
        'commodity' => "SELECT COALESCE(SUM(amount), 0) FROM tbl_commodity WHERE CoopID = ? AND Period <= ?",
        'commRepay' => "SELECT COALESCE(SUM(CommodityPayment), 0) FROM tbl_commodityrepayment WHERE CoopID = ? AND PaymentPeriod <= ?",
        'contri' => "SELECT COALESCE(SUM(MonthlyContribution), 0) FROM tbl_monthlycontribution WHERE CoopID = ? AND period = ?"
    ];

    $balances = [];
    foreach ($queries as $key => $query) {
        $stmt = $coop->prepare($query);
        if (!$stmt) throw new RuntimeException('Cannot prepare payroll balances');
        $stmt->execute([$coopID, $period]);
        $sum = $stmt->fetchColumn();
        $balances[$key] = moneyMinorUnits($sum);
    }

    $balances['lb'] = $balances['loan'] - $balances['loanRepay'];
    $balances['cb'] = $balances['commodity'] - $balances['commRepay'];

    // Validate negative balances
    if ($balances['cb'] < 0) {
        error_log("Negative commodity balance for CoopID: $coopID, period: $period, cb: {$balances['cb']}");
        $balances['cb'] = 0;
    }
    if ($balances['lb'] < 0) {
        error_log("Negative loan balance for CoopID: $coopID, period: $period, lb: {$balances['lb']}");
        $balances['lb'] = 0;
    }

    error_log(sprintf(
        "Raw balances - loan: %.2f, loanRepay: %.2f, commodity: %.2f, commRepay: %.2f, contri: %.2f, lb: %.2f, cb: %.2f for CoopID: %s, period: %s",
        $balances['loan'], $balances['loanRepay'], $balances['commodity'], $balances['commRepay'], $balances['contri'], $balances['lb'], $balances['cb'], $coopID, $period
    ));

    return $balances;
}

function hasEntryFeePaid($coop, $coopID) {
    $query = "SELECT COUNT(*) FROM tbl_entryfee WHERE CoopID = ?";
    $stmt = $coop->prepare($query);
    $stmt->execute([$coopID]);
    $count = $stmt->fetchColumn();
    return $count > 0;
}

function hasPendingLoan($coop, $coopID, $period) {
    $query = "SELECT COALESCE(SUM(LoanAmount), 0) FROM tbl_loanapproval WHERE CoopID = ? AND period = ?";
    $stmt = $coop->prepare($query);
    $stmt->execute([$coopID, $period]);
    $amount = $stmt->fetchColumn();
    return $amount > 0;
}

function insertTransaction($coop, $table, $coopID, $periodID, $field, $amount, $periodField = 'TransactionPeriod') {
    if ($table === 'tbl_loanapproval') {
        $query = "INSERT INTO tbl_loanapproval (CoopID, approvalDate, $field) VALUES (?, CURRENT_DATE, ?)";
        $params = [$coopID, $amount];
    } elseif ($table === 'tbl_loans' && func_num_args() > 7) {
        $remarks = func_get_arg(7);
        $query = "INSERT INTO tbl_loans (CoopID, LoanPeriod, LoanAmount, remarks) VALUES (?, ?, ?, ?)";
        $params = [$coopID, $periodID, $amount, $remarks];
    } else {
        $query = "INSERT INTO $table (CoopID, $periodField, $field) VALUES (?, ?, ?)";
        $params = [$coopID, $periodID, $amount];
    }
    
    $params[$table === 'tbl_loanapproval' ? 1 : 2] = moneyDecimal($amount);
    $stmt = $coop->prepare($query);
    if (!$stmt || !$stmt->execute($params)) throw new RuntimeException("Payroll insert failed for $table");
    return true;
}

function processEntryFee($coop, $coopID, $periodID, $contri, $entryFee) {
    if ($contri > 0 && $contri < $entryFee) {
        error_log("Insufficient contribution ($contri) for entry fee ($entryFee) for CoopID: $coopID, period: $periodID");
        return $contri; // Could insert partial payment if desired
    }
    if ($contri >= $entryFee) {
        insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'EntryFee', $entryFee);
        insertTransaction($coop, 'tbl_entryfee', $coopID, $periodID, 'AmountPaid', $entryFee, 'DeductionPeriod');
        return $contri - $entryFee;
    }
    return $contri;
}

function processDevLevy($coop, $coopID, $periodID, $contri, $devLevy) {
    if ($contri > 0 && $contri < $devLevy) {
        error_log("Insufficient contribution ($contri) for dev levy ($devLevy) for CoopID: $coopID, period: $periodID");
        return $contri;
    }
    if ($contri >= $devLevy) {
        insertTransaction($coop, 'tbl_devlevy', $coopID, $periodID, 'AmountPaid', $devLevy, 'DevPeriod');
        insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'DevLevy', $devLevy);
        return $contri - $devLevy;
    }
    return $contri;
}

function processStationery($coop, $coopID, $periodID, $contri, $stationery) {
    if ($contri > 0 && $contri < $stationery) {
        error_log("Insufficient contribution ($contri) for stationery ($stationery) for CoopID: $coopID, period: $periodID");
        return $contri;
    }
    if ($contri >= $stationery) {
        insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'Stationery', $stationery);
        insertTransaction($coop, 'tbl_stationery', $coopID, $periodID, 'AmountPaid', $stationery, 'StationeryPeriod');
        return $contri - $stationery;
    }
    return $contri;
}

function processCommodityRepayment($coop, $coopID, $periodID, $contri, $cb) {
    $payment = min(max(0, $contri), $cb);
    if ($payment > 0) {
        insertTransaction($coop, 'tbl_commodityrepayment', $coopID, $periodID, 'CommodityPayment', $payment, 'PaymentPeriod');
        insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'CommodityRepayment', $payment);
    }
    return $contri - $payment;
}

function processLoanRepayment($coop, $coopID, $periodID, $contri, $lb, $interestRate) {
    $intCharge = payrollRateAmount($lb, $interestRate);

    if ($contri == 0) {
        if ($intCharge > 0) {
            insertTransaction($coop, 'tbl_loans', $coopID, $periodID, 'LoanAmount', $intCharge, 'LoanPeriod', 'Capitalized Interest');
            insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'capitalized_interest', $intCharge);
            error_log("Zero contribution for CoopID: $coopID, period: $periodID; interest $intCharge capitalized to loan balance");
        }
        return 0;
    }

    if ($contri >= $intCharge) {
        $balanceAfterInterest = $contri - $intCharge;
        $repayment = $balanceAfterInterest < $lb ? intdiv($balanceAfterInterest, 1000) * 1000 : $lb;
        $savings = $balanceAfterInterest - $repayment;

        insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'InterestPaid', $intCharge);
        insertTransaction($coop, 'tbl_interest', $coopID, $periodID, 'IntAmount', $intCharge, 'InterestPeriod');
        if ($repayment > 0) {
            insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'loanRepayment', $repayment);
            insertTransaction($coop, 'tbl_loanrepayment', $coopID, $periodID, 'Repayment', $repayment, 'LoanRepaymentPeriod');
        }
        if ($savings > 0) {
            insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'savingsAmount', $savings);
            insertTransaction($coop, 'tbl_savings', $coopID, $periodID, 'AmountPaid', $savings, 'DeductionPeriod');
        }
        error_log("Sufficient contribution ($contri) for CoopID: $coopID, period: $periodID; interest paid: $intCharge, repayment: $repayment, savings: $savings");
        return 0;
    }

    // Partial interest payment
    $balanceAfterInterest = $intCharge - $contri;
    insertTransaction($coop, 'tbl_loans', $coopID, $periodID, 'LoanAmount', $balanceAfterInterest, 'LoanPeriod', 'Capitalized Interest');
    insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'capitalized_interest', $balanceAfterInterest);
    insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'InterestPaid', $contri);
    insertTransaction($coop, 'tbl_interest', $coopID, $periodID, 'IntAmount', $contri, 'InterestPeriod');
    error_log("Partial interest payment ($contri) for CoopID: $coopID, period: $periodID; remaining interest $balanceAfterInterest added to loan");
    return 0;
}

function processSavingsAndShares($coop, $coopID, $periodID, $contri, $savingsRate, $sharesRate) {
    if ($savingsRate + $sharesRate > 1000000) throw new RuntimeException('Savings and shares rates exceed the contribution');
    payrollHoldingConfiguration($coop);
    $sharesAmount = payrollRateFloorAmount($contri, $sharesRate);
    $savingsAmount = payrollRateFloorAmount($contri, $savingsRate);
    recordPayrollUnallocated($coop, $coopID, $periodID, $contri - $sharesAmount - $savingsAmount);

    error_log("Processing savings: $savingsAmount, shares: $sharesAmount for CoopID: $coopID, period: $periodID");

    insertTransaction($coop, 'tbl_shares', $coopID, $periodID, 'sharesAmount', $sharesAmount, 'SharesPeriod');
    insertTransaction($coop, 'tbl_savings', $coopID, $periodID, 'AmountPaid', $savingsAmount, 'DeductionPeriod');
    insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'savingsAmount', $savingsAmount);
    insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'sharesAmount', $sharesAmount);
}

function processPendingLoans($coop, $coopID, $periodID) {
    $query = "SELECT COALESCE(SUM(LoanAmount), 0) FROM tbl_loanapproval WHERE CoopID = ? AND period = ?";
    $stmt = $coop->prepare($query);
    $stmt->execute([$coopID, $periodID]);
    $lapp = moneyMinorUnits($stmt->fetchColumn());

    if ($lapp !== 0) {
        throw new RuntimeException('Legacy loan approval is not proof of payment. Canonical verified disbursement linkage is required before payroll can post a loan.');
    }
}

function processPendingCommodity($coop, $coopID, $periodID) {
    $query = "SELECT COALESCE(SUM(amount), 0) FROM tbl_commodity WHERE coopID = ? AND Period = ?";
    $stmt = $coop->prepare($query);
    $stmt->execute([$coopID, $periodID]);
    $capp = moneyMinorUnits($stmt->fetchColumn());

    if ($capp > 0) {
        insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'Commodity', $capp, 'TransactionPeriod');
    }
}

function processLoanSavings($coop, $coopID, $periodID) {
    $query = "SELECT COALESCE(SUM(Amount), 0) FROM tbl_loansavings
              INNER JOIN tblemployees ON tblemployees.CoopID = tbl_loansavings.COOPID 
              WHERE `Status` = 'Active' AND tbl_loansavings.COOPID = ? and tbl_loansavings.period = ?";
    $stmt = $coop->prepare($query);
    $stmt->execute([$coopID, $periodID]);
    $amount = moneyMinorUnits($stmt->fetchColumn());

    if ($amount > 0) {
        insertTransaction($coop, 'tbl_shares', $coopID, $periodID, 'sharesAmount', $amount, 'SharesPeriod');
        insertTransaction($coop, 'tbl_mastertransact', $coopID, $periodID, 'sharesAmount', $amount);
    }
}

/**
 * Create automatic journal entry for member monthly deduction
 * 
 * This function creates a double-entry journal entry that records:
 * - Bank receipts (Debit)
 * - Member contributions to Shares, Savings, Loans, etc. (Credit)
 */
function createMemberJournalEntry($accountingEngine, $coop, $coopID, $periodID) {
    // Get member name for description
    $memberQuery = "SELECT CONCAT(FirstName, ' ', LastName) as name FROM tblemployees WHERE CoopID = ?";
    $stmt = $coop->prepare($memberQuery);
    $stmt->execute([$coopID]);
    $memberData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $memberName = $memberData['name'] ?? $coopID;
    
    // Get all transaction amounts for this member in this period
    $transQuery = "SELECT 
                    COALESCE(SUM(EntryFee), 0) as entry_fee,
                    COALESCE(SUM(savingsAmount), 0) as savings,
                    COALESCE(SUM(sharesAmount), 0) as shares,
                    COALESCE(SUM(DevLevy), 0) as dev_levy,
                    COALESCE(SUM(Stationery), 0) as stationery,
                    COALESCE(SUM(InterestPaid), 0) as interest_paid,
                    COALESCE(SUM(loanRepayment), 0) as loan_repayment,
                    COALESCE(SUM(CommodityRepayment), 0) as commodity_repayment,
                    COALESCE(SUM(Commodity), 0) as commodity,
                    COALESCE(SUM(capitalized_interest), 0) as capitalized_interest
                FROM tbl_mastertransact 
                WHERE CoopID = ? AND TransactionPeriod = ?";
    
    $stmt = $coop->prepare($transQuery);
    $stmt->execute([$coopID, $periodID]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$trans) {
        error_log("No transaction found for journal entry: $coopID, period: $periodID");
        return;
    }
    
    foreach ($trans as $key => $value) $trans[$key] = moneyMinorUnits($value);

    $heldStmt = $coop->prepare('SELECT * FROM coop_payroll_unallocated WHERE coop_id = ? AND period_id = ? ORDER BY id FOR UPDATE');
    $heldStmt->execute([$coopID, $periodID]);
    $heldRows = $heldStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalHeld = 0;
    foreach ($heldRows as $held) {
        if ($held['journal_entry_id'] !== null) throw new RuntimeException('Held payroll funds are already journaled');
        $totalHeld = moneyAdd($totalHeld, moneyMinorUnits($held['amount']));
    }

    // Calculate total received from member
    $totalReceived = $trans['entry_fee'] + $trans['savings'] + $trans['shares'] + 
                     $trans['dev_levy'] + $trans['stationery'] + $trans['interest_paid'] + 
                     $trans['loan_repayment'] + $trans['commodity_repayment'];
    $totalReceived = moneyAdd($totalReceived, $totalHeld);
    
    // Total for non-cash income (Accrued but capitalized)
    $totalNonCash = $trans['capitalized_interest'];
    
    // Skip if no transaction amount
    if ($totalReceived <= 0 && $totalNonCash <= 0) {
        return;
    }
    
    // Build journal entry lines
    $lines = [];
    
    // DEBIT: Bank Account (Money received)
    if ($totalReceived > 0) $lines[] = [
        'account_id' => payrollJournalAccount($accountingEngine, '1102'),  // Bank - Main Account (1102)
        'debit_amount' => $totalReceived,
        'credit_amount' => 0,
        'description' => "Monthly deduction from $memberName",
        'reference_type' => 'member',
        'reference_id' => $coopID
    ];
    
    // CREDITS: Member accounts (What the money was for)
    
    // 1. Entrance Fee Income
    if ($trans['entry_fee'] > 0) {
        $lines[] = [
            'account_id' => payrollJournalAccount($accountingEngine, '4101'),  // Entrance Fees Income (4101)
            'debit_amount' => 0,
            'credit_amount' => $trans['entry_fee'],
            'description' => "Entrance fee from $memberName"
        ];
    }
    
    // 2. Ordinary Savings
    if ($trans['savings'] > 0) {
        $lines[] = [
            'account_id' => payrollJournalAccount($accountingEngine, '3201'),  // Ordinary Savings (3201)
            'debit_amount' => 0,
            'credit_amount' => $trans['savings'],
            'description' => "Savings contribution from $memberName",
            'reference_type' => 'member',
            'reference_id' => $coopID
        ];
    }
    
    // 3. Ordinary Shares
    if ($trans['shares'] > 0) {
        $lines[] = [
            'account_id' => payrollJournalAccount($accountingEngine, '3101'),  // Ordinary Shares (3101)
            'debit_amount' => 0,
            'credit_amount' => $trans['shares'],
            'description' => "Shares contribution from $memberName",
            'reference_type' => 'member',
            'reference_id' => $coopID
        ];
    }
    
    // 4. Dev Levy (Operating Revenue or Other Income)
    if ($trans['dev_levy'] > 0) {
        $lines[] = [
            'account_id' => payrollJournalAccount($accountingEngine, '4299'),  // Miscellaneous Income (4299)
            'debit_amount' => 0,
            'credit_amount' => $trans['dev_levy'],
            'description' => "Development levy from $memberName"
        ];
    }
    
    // 5. Stationery (Operating Revenue)
    if ($trans['stationery'] > 0) {
        $lines[] = [
            'account_id' => payrollJournalAccount($accountingEngine, '4299'),  // Miscellaneous Income (4299)
            'debit_amount' => 0,
            'credit_amount' => $trans['stationery'],
            'description' => "Stationery fee from $memberName"
        ];
    }
    
    // 6. Interest Paid on Loans
    if ($trans['interest_paid'] > 0) {
        $lines[] = [
            'account_id' => payrollJournalAccount($accountingEngine, '4102'),  // Interest on Loans (4102)
            'debit_amount' => 0,
            'credit_amount' => $trans['interest_paid'],
            'description' => "Interest on loan from $memberName"
        ];
    }
    
    // 7. Loan Repayment (Reduces Member Loans Asset)
    if ($trans['loan_repayment'] > 0) {
        $lines[] = [
            'account_id' => payrollJournalAccount($accountingEngine, '1110'),  // Member Loans (1110)
            'debit_amount' => 0,
            'credit_amount' => $trans['loan_repayment'],
            'description' => "Loan repayment from $memberName",
            'reference_type' => 'member',
            'reference_id' => $coopID
        ];
    }
    
    // 8. Commodity Repayment
    if ($trans['commodity_repayment'] > 0) {
        $lines[] = [
            'account_id' => payrollJournalAccount($accountingEngine, '1120'),  // Account Receivables (1120)
            'debit_amount' => 0,
            'credit_amount' => $trans['commodity_repayment'],
            'description' => "Commodity repayment from $memberName"
        ];
    }
    
    // 9. Capitalized Interest (Non-cash Income)
    if ($trans['capitalized_interest'] > 0) {
        // DEBIT: Member Loans (Asset increase)
        $lines[] = [
            'account_id' => payrollJournalAccount($accountingEngine, '1110'),  // Member Loans (1110)
            'debit_amount' => $trans['capitalized_interest'],
            'credit_amount' => 0,
            'description' => "Capitalized interest added to loan for $memberName",
            'reference_type' => 'member',
            'reference_id' => $coopID
        ];
        
        // CREDIT: Interest on Loans (Income)
        $lines[] = [
            'account_id' => payrollJournalAccount($accountingEngine, '4102'),  // Interest on Loans (4102)
            'debit_amount' => 0,
            'credit_amount' => $trans['capitalized_interest'],
            'description' => "Interest income (capitalized) from $memberName"
        ];
    }
    
    foreach ($heldRows as $held) {
        $lines[] = [
            'account_id' => $held['holding_account_id'],
            'debit_amount' => 0,
            'credit_amount' => moneyMinorUnits($held['amount']),
            'description' => "Unallocated payroll deduction for $memberName: " . $held['reason'],
            'reference_type' => 'member',
            'reference_id' => $coopID
        ];
    }
    foreach ($lines as &$line) {
        $line['debit_amount'] = moneyDecimal($line['debit_amount']);
        $line['credit_amount'] = moneyDecimal($line['credit_amount']);
    }
    unset($line);
    // Create the journal entry
    $result = $accountingEngine->createJournalEntry(
        $periodID,
        date('Y-m-d'),
        'member_transaction',
        "Monthly deduction - $memberName (Period $periodID)",
        $lines,
        1,  // System user ID
        "DEDUCT-$coopID-$periodID"
    );
    
    if ($result['success']) {
        // Automatically post the entry
        $postResult = $accountingEngine->postEntry($result['entry_id']);
        if ($postResult['success']) {
            foreach ($heldRows as $held) {
                $link = $coop->prepare('UPDATE coop_payroll_unallocated SET journal_entry_id = ? WHERE id = ? AND journal_entry_id IS NULL');
                $link->execute([$result['entry_id'], $held['id']]);
                if ($link->rowCount() !== 1) throw new RuntimeException('Held payroll journal linkage failed');
            }
            error_log("Journal entry created and posted for $coopID: Entry #{$result['entry_number']}");
        } else {
            throw new RuntimeException("Journal posting failed: " . ($postResult['error'] ?? 'unknown error'));
        }
    } else {
        throw new RuntimeException("Journal creation failed: " . ($result['error'] ?? 'unknown error'));
    }
}

function updateProgress($current, $total, $coopID) {
    if ($total > 0) {
        $percent = intval(($current / $total) * 100);
    } else {
        $percent = 100;
    }
    $percentDisplay = $percent . "%";
    
    // Debug logging
    error_log("Progress Update: $current/$total = $percentDisplay for $coopID");
    
    // Send clean progress data that can be easily parsed
    echo str_repeat(' ', 1024 * 64);
    echo "PROGRESS_DATA: Processing $coopID ($current of $total employees) - $percentDisplay\n";
    // echo "DEBUG: Sent progress data for $coopID\n";
    ob_flush();
    flush();
}
function payrollJournalAccount($engine, $code) {
    $account = $engine->getAccountByCode($code);
    if (!$account) throw new RuntimeException("Missing payroll journal account $code");
    return $account['id'];
}
