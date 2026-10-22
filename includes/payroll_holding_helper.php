<?php
require_once __DIR__ . '/money_helper.php';

function payrollHoldingConfiguration($db): array {
    if (!$db->inTransaction()) throw new LogicException('Payroll holding configuration requires a transaction');
    $stmt = $db->query('SELECT * FROM coop_payroll_configuration WHERE id = 1 FOR UPDATE');
    $configuration = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$configuration) throw new RuntimeException('Select a payroll holding ledger account during setup before processing deductions');
    $stmt = $db->prepare('SELECT id FROM coop_accounts WHERE id = ? AND is_active = 1 AND is_control_account = 0 FOR UPDATE');
    $stmt->execute([$configuration['holding_account_id']]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('Configured payroll holding ledger account is unavailable for posting');
    return $configuration;
}

/** Caller authenticates/authorizes the administrator and owns the transaction. */
function configurePayrollHoldingAccount($db, $accountId, $actorId): array {
    if (!$db->inTransaction()) throw new LogicException('Payroll setup requires a transaction');
    foreach ([$accountId, $actorId] as $id) {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]*\z/', (string)$id) || (int)$id > 2147483647) throw new InvalidArgumentException('A valid account and administrator identifier are required');
    }
    $stmt = $db->query('SELECT * FROM coop_payroll_configuration WHERE id = 1 FOR UPDATE');
    $before = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt = $db->prepare('SELECT id FROM coop_accounts WHERE id = ? AND is_active = 1 AND is_control_account = 0 FOR UPDATE');
    $stmt->execute([$accountId]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Select an existing active posting ledger account');
    $stmt = $db->prepare('INSERT INTO coop_payroll_configuration (id, holding_account_id, configured_by) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE holding_account_id = VALUES(holding_account_id), configured_by = VALUES(configured_by), configured_at = CURRENT_TIMESTAMP');
    $stmt->execute([$accountId, $actorId]);
    $configuration = payrollHoldingConfiguration($db);
    $stmt = $db->prepare("INSERT INTO coop_audit_trail (user_id, action_type, table_name, record_id, old_values, new_values) VALUES (?, 'update', 'coop_payroll_configuration', 1, ?, ?)");
    $stmt->execute([$actorId, $before ? json_encode($before) : null, json_encode($configuration)]);
    return $configuration;
}

function recordPayrollUnallocated($db, $coopId, $periodId, int $amount, string $reason = 'allocation_remainder'): void {
    if ($amount === 0) return;
    if ($amount < 0) throw new InvalidArgumentException('Unallocated payroll amount cannot be negative');
    $configuration = payrollHoldingConfiguration($db);
    $sourceKey = 'deduction-' . $reason;
    $stmt = $db->prepare("SELECT * FROM coop_payroll_unallocated WHERE source_type = 'payroll' AND source_key = ? AND coop_id = ? AND period_id = ? FOR UPDATE");
    $stmt->execute([$sourceKey, $coopId, $periodId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if (moneyMinorUnits($existing['amount']) !== $amount || (int)$existing['holding_account_id'] !== (int)$configuration['holding_account_id']) throw new RuntimeException('Payroll remainder retry conflicts with existing held funds');
        return;
    }
    $stmt = $db->prepare("INSERT INTO coop_payroll_unallocated (source_type, source_key, coop_id, period_id, reason, amount, holding_account_id) VALUES ('payroll', ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$sourceKey, $coopId, $periodId, $reason, moneyDecimal($amount), $configuration['holding_account_id']]);
}
