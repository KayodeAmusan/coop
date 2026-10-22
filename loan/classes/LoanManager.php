<?php

class LoanManager {
    private $connection;
    private $database;
    
    public function __construct($connection, $database) {
        $this->connection = $connection;
        $this->database = $database;
    }
    
    /**
     * Get all payroll periods
     */
    public function getPayrollPeriods() {
        try {
            $sql = "SELECT id, PayrollPeriod, PhysicalYear, PhysicalMonth, Remarks 
                    FROM tbpayrollperiods 
                    ORDER BY id DESC";
            $stmt = $this->connection->query($sql);
            
            if (!$stmt) {
                throw new Exception("Query failed");
            }
            
            $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $periods;
        } catch (Exception $e) {
            error_log("Error fetching payroll periods: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Insert loan into tbl_loans
     */
    public function insertLoan($data) {
        return ['success' => false, 'message' => 'Legacy loan mutations are unavailable pending configured products, verified guarantor evidence and canonical disbursement controls.'];
    }
    
    /**
     * Get loans by payroll period
     */
    public function getLoansByPeriod($payrollPeriodId) {
        try {
            $sql = "SELECT 
                        l.loan_id,
                        l.CoopID,
                        l.DateOfLoanApp,
                        l.LoanAmount,
                        l.MonthlyRepayment,
                        l.LoanStatus,
                        l.StationeryStatus,
                        l.LoanPeriod,
                        CONCAT(e.FirstName, ' ', e.MiddleName, ' ', e.LastName) AS FullName
                    FROM tbl_loans l
                    LEFT JOIN tblemployees e ON l.CoopID = e.CoopID
                    WHERE l.LoanStatus = 1
                    ORDER BY l.DateOfLoanApp DESC";
            
            // Note: Currently not filtering by payrollPeriodId as per original code structure?
            // Re-adding filter if intended, though original query ignored the argument. 
            // Assuming original intent was to filter? The SQL string didn't use variable.
            // Let's stick to original behavior but use the db connection properly.
            
            $stmt = $this->connection->query($sql);
            if (!$stmt) {
                 throw new Exception("Query failed");
            }
            
            $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $loans;
        } catch (Exception $e) {
            error_log("Error fetching loans: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get loan statistics
     */
    public function getLoanStatistics() {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_loans,
                        SUM(LoanAmount) as total_amount,
                        AVG(LoanAmount) as average_amount,
                        COUNT(CASE WHEN LoanStatus = 1 THEN 1 END) as active_loans
                    FROM tbl_loans";
            
            $stmt = $this->connection->query($sql);
            if (!$stmt) {
                 throw new Exception("Query failed");
            }
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $stats;
        } catch (Exception $e) {
            error_log("Error fetching loan statistics: " . $e->getMessage());
            return [
                'total_loans' => 0,
                'total_amount' => 0,
                'average_amount' => 0,
                'active_loans' => 0
            ];
        }
    }
    
    /**
     * Get beneficiaries from excel table for a specific batch
     */
    public function getBatchBeneficiaries($batchNumber) {
        try {
            $sql = "SELECT 
                        BeneficiaryCode,
                        BeneficiaryName,
                        Amount,
                        Bank,
                        AccountNumber,
                        Narration
                    FROM excel 
                    WHERE Batch = ?";
            
            error_log("Executing query: " . $sql);
            
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$batchNumber]);
            
            $beneficiaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Found " . count($beneficiaries) . " beneficiaries for batch: $batchNumber");
            return $beneficiaries;
        } catch (Exception $e) {
            error_log("Error fetching batch beneficiaries: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Post account - Insert beneficiaries as loan approvals
     */
    public function postAccount($batchNumber, $loanPeriod, $payrollPeriodId, $selectedBeneficiaries = null) {
        return ['success' => false, 'message' => 'Legacy loan mutations are unavailable pending configured products, verified guarantor evidence and canonical disbursement controls.'];
    }
}
