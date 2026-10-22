<?php
require_once __DIR__ . '/../../includes/money_helper.php';

/** Exact balance decisions; legacy numeric fields remain presentation boundaries. */
class AccountBalanceCalculator
{
    private PDO $conn;
    public function __construct(PDO $conn, $database_name = null) { $this->conn = $conn; }

    public function getAccountBalance(int $account_id, int $periodid): array
    {
        try {
            $stmt = $this->conn->prepare('SELECT * FROM coop_accounts WHERE id = :id');
            $stmt->execute([':id' => $account_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$account) throw new RuntimeException('Account not found');
            $stmt = $this->conn->prepare('SELECT opening_debit, opening_credit, period_debit, period_credit
                FROM coop_period_balances WHERE periodid = :period AND account_id = :account');
            $stmt->execute([':period'=>$periodid, ':account'=>$account_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $od = moneyMinorUnits($row['opening_debit'] ?? '0.00');
            $oc = moneyMinorUnits($row['opening_credit'] ?? '0.00');
            $dr = moneyAdd($od,moneyMinorUnits($row['period_debit'] ?? '0.00'));
            $cr = moneyAdd($oc,moneyMinorUnits($row['period_credit'] ?? '0.00'));
            $net = $account['normal_balance'] === 'debit' ? moneyAdd($dr,-$cr) : moneyAdd($cr,-$dr);
            return [
                'balance'=>(float)moneyDecimal($net), 'balance_decimal'=>moneyDecimal($net),
                'debit'=>(float)moneyDecimal($dr), 'debit_decimal'=>moneyDecimal($dr),
                'credit'=>(float)moneyDecimal($cr), 'credit_decimal'=>moneyDecimal($cr),
                'opening_debit'=>(float)moneyDecimal($od), 'opening_credit'=>(float)moneyDecimal($oc),
                'normal_balance'=>$account['normal_balance']
            ];
        } catch (Throwable $e) {
            error_log('Account balance calculation failed: '.$e->getMessage());
            return ['balance'=>0, 'balance_decimal'=>'0.00', 'debit'=>0, 'credit'=>0,
                'opening_debit'=>0, 'opening_credit'=>0, 'normal_balance'=>'debit', 'error'=>$e->getMessage()];
        }
    }

    public function getTrialBalance(int $periodid, bool $includeZeroBalances = false): array
    {
        $stmt=$this->conn->prepare('SELECT a.*,
            COALESCE(pb.opening_debit,0) opening_debit, COALESCE(pb.opening_credit,0) opening_credit,
            COALESCE(pb.period_debit,0) period_debit, COALESCE(pb.period_credit,0) period_credit
            FROM coop_accounts a LEFT JOIN coop_period_balances pb
                ON a.id=pb.account_id AND pb.periodid=:period
            ORDER BY a.account_code');
        $stmt->execute([':period'=>$periodid]);
        $accounts=[]; $totalDr=0; $totalCr=0;
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $dr=moneyAdd(moneyMinorUnits($row['opening_debit']),moneyMinorUnits($row['period_debit']));
            $cr=moneyAdd(moneyMinorUnits($row['opening_credit']),moneyMinorUnits($row['period_credit']));
            $net=moneyAdd($dr,-$cr);
            if(!$includeZeroBalances && $net===0) continue;
            $debit=max(0,$net); $credit=max(0,-$net);
            $accounts[]=['account_code'=>$row['account_code'],'account_name'=>$row['account_name'],
                'account_type'=>$row['account_type'],
                'debit_balance'=>(float)moneyDecimal($debit),'credit_balance'=>(float)moneyDecimal($credit),
                'debit_balance_decimal'=>moneyDecimal($debit),'credit_balance_decimal'=>moneyDecimal($credit)];
            $totalDr=moneyAdd($totalDr,$debit); $totalCr=moneyAdd($totalCr,$credit);
        }
        $difference=moneyAdd($totalDr,-$totalCr);
        return ['accounts'=>$accounts,'totals'=>[
            'debit'=>(float)moneyDecimal($totalDr),'credit'=>(float)moneyDecimal($totalCr),
            'difference'=>(float)moneyDecimal($difference),
            'debit_decimal'=>moneyDecimal($totalDr),'credit_decimal'=>moneyDecimal($totalCr),
            'difference_decimal'=>moneyDecimal($difference)],'is_balanced'=>$difference===0];
    }

    public function verifyAccountingEquation(int $periodid): array
    {
        $assets=moneyMinorUnits($this->getTotalByTypeDecimal($periodid,'asset'));
        $liabilities=moneyMinorUnits($this->getTotalByTypeDecimal($periodid,'liability'));
        $recordedEquity=moneyMinorUnits($this->getTotalByTypeDecimal($periodid,'equity'));
        $earnings=moneyAdd(moneyMinorUnits($this->getTotalByTypeDecimal($periodid,'revenue')),
            -moneyMinorUnits($this->getTotalByTypeDecimal($periodid,'expense')));
        $equity=moneyAdd($recordedEquity,$earnings);
        $difference=moneyAdd($assets,-moneyAdd($liabilities,$equity));
        return ['valid'=>$difference===0,'assets'=>(float)moneyDecimal($assets),
            'liabilities'=>(float)moneyDecimal($liabilities),'equity'=>(float)moneyDecimal($equity),
            'current_earnings'=>(float)moneyDecimal($earnings),'recorded_equity'=>(float)moneyDecimal($recordedEquity),
            'difference'=>(float)moneyDecimal($difference),'difference_decimal'=>moneyDecimal($difference)];
    }

    public function getTotalByType(int $periodid, string $type): float
    {
        return (float)$this->getTotalByTypeDecimal($periodid,$type);
    }

    public function getTotalByTypeDecimal(int $periodid, string $type): string
    {
        if(!in_array($type,['asset','liability','equity','revenue','expense'],true)) {
            throw new InvalidArgumentException('Unknown account type');
        }
        $stmt=$this->conn->prepare('SELECT
            COALESCE(SUM(COALESCE(pb.opening_debit,0)+COALESCE(pb.period_debit,0)),0) debit,
            COALESCE(SUM(COALESCE(pb.opening_credit,0)+COALESCE(pb.period_credit,0)),0) credit
            FROM coop_accounts a LEFT JOIN coop_period_balances pb
                ON a.id=pb.account_id AND pb.periodid=:period
            WHERE a.account_type=:type');
        $stmt->execute([':period'=>$periodid,':type'=>$type]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        $dr=moneyMinorUnits($row['debit']); $cr=moneyMinorUnits($row['credit']);
        return moneyDecimal(in_array($type,['asset','expense'],true) ? moneyAdd($dr,-$cr) : moneyAdd($cr,-$dr));
    }
}
