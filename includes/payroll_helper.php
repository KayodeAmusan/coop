<?php
require_once __DIR__ . '/money_helper.php';
require_once __DIR__ . '/payroll_holding_helper.php';
function payrollRate($value): int {
    if (!preg_match('/\A([01])(?:\.([0-9]{1,6}))?\z/', (string)$value, $parts)) {
        throw new InvalidArgumentException('Payroll rates must be decimals from zero to one with at most six places');
    }
    $rate = (int)$parts[1] * 1000000 + (int)str_pad($parts[2] ?? '', 6, '0');
    if ($rate > 1000000) throw new InvalidArgumentException('Payroll rate exceeds one');
    return $rate;
}
function payrollRateAmount(int $minor, int $rate): int {
    if ($minor < 0 || $rate < 0 || $rate > 1000000) throw new InvalidArgumentException('Invalid payroll allocation');
    moneyDecimal($minor);
    // Split before multiplication to keep DECIMAL(15,2) amounts inside 64-bit integers.
    return moneyAdd(intdiv($minor, 1000000) * $rate, intdiv(($minor % 1000000) * $rate + 500000, 1000000));
}
function lockPayrollRun($db, $periodID) {
    if (!$db->inTransaction()) throw new LogicException('Payroll requires a transaction');
    $stmt = $db->prepare('SELECT id FROM tbpayrollperiods WHERE id = ? FOR UPDATE');
    $stmt->execute([$periodID]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('Payroll period not found');
}

function payrollRateFloorAmount(int $minor, int $rate): int {
    if ($minor < 0 || $rate < 0 || $rate > 1000000) throw new InvalidArgumentException('Invalid payroll allocation');
    moneyDecimal($minor);
    return moneyAdd(intdiv($minor, 1000000) * $rate, intdiv(($minor % 1000000) * $rate, 1000000));
}
