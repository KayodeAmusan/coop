<?php

header('Cache-Control: no-store');
require_once('Connections/coop.php');
require_once('libs/services/BankReconciliationService.php');
require_once('libs/services/AccountBalanceCalculator.php');
require_once('includes/header.php');
require_once('includes/accounting_nav.php');

$reconService = new BankReconciliationService($coop, $database);
$calculator = new AccountBalanceCalculator($coop, $database);

// Get bank accounts
$bankAccounts = [];
$bankQuery = "SELECT id, account_code, account_name 
              FROM coop_accounts 
              WHERE account_code LIKE '110%' AND is_active = TRUE
              ORDER BY account_code";
try {
    $stmt = $coop->query($bankQuery);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $bankAccounts[] = $row;
    }
} catch (PDOException $e) {
    // Handle error
}

// Get periods
$periods = [];
$periodQuery = "SELECT id, PayrollPeriod FROM tbpayrollperiods ORDER BY id DESC";
try {
    $stmt = $coop->query($periodQuery);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $periods[] = $row;
    }
} catch (PDOException $e) {
    // Handle error
}

// Get reconciliation history
$history = $reconService->getReconciliationHistory(null, 10);
?>

<div class="container mx-auto px-4 py-8 max-w-6xl">
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <h1 class="text-3xl font-bold text-blue-900">🏦 Bank Reconciliation</h1>
        <p class="text-gray-600 mt-1">Match your books with bank statements</p>
    </div>

    <!-- Reconciliation Form -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-900 mb-4">New Reconciliation</h2>

        <form id="reconForm">
            <input type="hidden" name="idempotency_key" id="recon-idempotency-key" value="<?php echo bin2hex(random_bytes(24)); ?>">
            <p id="recon-submission-status" role="status" class="text-sm text-gray-700 mb-4">A submission reference protects this reconciliation from duplicate retries.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Bank Account <span class="text-red-500">*</span>
                    </label>
                    <select name="bank_account_id" id="bankAccountSelect"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2" required>
                        <option value="">-- Select Bank Account --</option>
                        <?php foreach ($bankAccounts as $account): ?>
                        <option value="<?php echo $account['id']; ?>">
                            <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Period <span class="text-red-500">*</span>
                    </label>
                    <select name="periodid" id="periodSelect" class="w-full border border-gray-300 rounded-lg px-4 py-2"
                        required>
                        <option value="">-- Select Period --</option>
                        <?php foreach ($periods as $period): ?>
                        <option value="<?php echo $period['id']; ?>">
                            <?php echo htmlspecialchars($period['PayrollPeriod']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Reconciliation Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="reconciliation_date" value="<?php echo date('Y-m-d'); ?>"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2" required>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Book Balance (₦) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="book_balance" id="bookBalance" step="0.01"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 font-mono" placeholder="0.00"
                        readonly>
                    <p class="text-xs text-gray-500 mt-1">Auto-calculated from your accounts</p>
                </div>

                <div class="md:col-span-2 border-t pt-4">
                    <h3 class="font-bold text-gray-900 mb-3">Bank Statement Details</h3>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Bank Statement Balance (₦) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="bank_statement_balance" id="bankBalance" step="0.01"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 font-mono" placeholder="0.00"
                        required>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Outstanding Deposits (₦)
                    </label>
                    <input type="number" name="outstanding_deposits" id="outstandingDeposits" step="0.01" min="0" value="0.00" required
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 font-mono recon-input"
                        placeholder="0.00">
                    <p class="text-xs text-gray-500 mt-1">Deposits made but not yet on statement</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Outstanding Withdrawals (₦)
                    </label>
                    <input type="number" name="outstanding_withdrawals" id="outstandingWithdrawals" step="0.01" min="0" value="0.00" required
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 font-mono recon-input"
                        placeholder="0.00">
                    <p class="text-xs text-gray-500 mt-1">Checks/payments not yet cleared</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Bank Charges (₦)
                    </label>
                    <input type="number" name="bank_charges" id="bankCharges" step="0.01" min="0" value="0.00" required
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 font-mono recon-input"
                        placeholder="0.00">
                    <p class="text-xs text-gray-500 mt-1">Charges not yet recorded in books</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Bank Interest (₦)
                    </label>
                    <input type="number" name="bank_interest" id="bankInterest" step="0.01" min="0" value="0.00" required
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 font-mono recon-input"
                        placeholder="0.00">
                    <p class="text-xs text-gray-500 mt-1">Interest earned not yet recorded</p>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Notes</label>
                    <textarea name="notes" rows="2" class="w-full border border-gray-300 rounded-lg px-4 py-2"
                        placeholder="Any additional notes about this reconciliation..."></textarea>
                </div>
            </div>

            <!-- Reconciliation Calculation -->
            <div class="bg-blue-50 border-2 border-blue-200 rounded-lg p-6 mb-4">
                <h3 class="font-bold text-gray-900 mb-4">Reconciliation Calculation</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Bank Side -->
                    <div class="bg-white rounded-lg p-4">
                        <h4 class="font-semibold text-blue-900 mb-3">Bank Statement Side</h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span>Bank Statement Balance:</span>
                                <span id="displayBankBalance" class="font-mono">₦0.00</span>
                            </div>
                            <div class="flex justify-between text-green-600">
                                <span>Add: Outstanding Deposits:</span>
                                <span id="displayOutDeposits" class="font-mono">₦0.00</span>
                            </div>
                            <div class="flex justify-between text-red-600">
                                <span>Less: Outstanding Withdrawals:</span>
                                <span id="displayOutWithdrawals" class="font-mono">₦0.00</span>
                            </div>
                            <div class="flex justify-between font-bold text-lg pt-2 border-t">
                                <span>Adjusted Bank Balance:</span>
                                <span id="adjustedBankBalance" class="font-mono">₦0.00</span>
                            </div>
                        </div>
                    </div>

                    <!-- Book Side -->
                    <div class="bg-white rounded-lg p-4">
                        <h4 class="font-semibold text-purple-900 mb-3">Book Balance Side</h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span>Book Balance:</span>
                                <span id="displayBookBalance" class="font-mono">₦0.00</span>
                            </div>
                            <div class="flex justify-between text-green-600">
                                <span>Add: Bank Interest:</span>
                                <span id="displayBankInterest" class="font-mono">₦0.00</span>
                            </div>
                            <div class="flex justify-between text-red-600">
                                <span>Less: Bank Charges:</span>
                                <span id="displayBankCharges" class="font-mono">₦0.00</span>
                            </div>
                            <div class="flex justify-between font-bold text-lg pt-2 border-t">
                                <span>Adjusted Book Balance:</span>
                                <span id="adjustedBookBalance" class="font-mono">₦0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Variance -->
                <div class="mt-4 p-4 rounded-lg" id="varianceDisplay">
                    <div class="flex justify-between items-center">
                        <span class="font-bold text-lg">VARIANCE:</span>
                        <span id="variance" class="font-bold text-2xl font-mono">₦0.00</span>
                    </div>
                    <p id="varianceStatus" class="text-sm mt-2"></p>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="flex justify-end gap-4">
                <button type="button" onclick="resetForm()"
                    class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg">
                    <i class="fa fa-redo mr-1"></i> Start New Reconciliation
                </button>
                <button type="submit" id="recon-submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                    <i class="fa fa-check mr-1"></i> Complete Reconciliation
                </button>
            </div>
        </form>
    </div>

    <!-- Reconciliation History -->
    <?php if (count($history) > 0): ?>
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="px-6 py-4 bg-gradient-to-r from-teal-600 to-teal-700 text-white">
            <h2 class="text-xl font-bold">Reconciliation History</h2>
            <p class="text-sm text-teal-100">Recent reconciliations</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-3 text-left">Date</th>
                        <th class="px-4 py-3 text-left">Account</th>
                        <th class="px-4 py-3 text-left">Period</th>
                        <th class="px-4 py-3 text-right">Bank Balance</th>
                        <th class="px-4 py-3 text-right">Book Balance</th>
                        <th class="px-4 py-3 text-right">Variance</th>
                        <th class="px-4 py-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($history as $recon): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3"><?php echo date('d M Y', strtotime($recon['reconciliation_date'])); ?>
                        </td>
                        <td class="px-4 py-3 font-mono text-sm"><?php echo htmlspecialchars($recon['account_code']); ?>
                        </td>
                        <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($recon['PayrollPeriod']); ?></td>
                        <td class="px-4 py-3 text-right font-mono">
                            ₦<?php echo number_format($recon['bank_statement_balance'], 2); ?></td>
                        <td class="px-4 py-3 text-right font-mono">
                            ₦<?php echo number_format($recon['book_balance'], 2); ?></td>
                        <td
                            class="px-4 py-3 text-right font-mono <?php echo abs($recon['variance']) < 0.01 ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo abs($recon['variance']) < 0.01 ? '-' : '₦' . number_format(abs($recon['variance']), 2); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($recon['is_balanced']): ?>
                            <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                ✓ Balanced
                            </span>
                            <?php else: ?>
                            <span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                ✗ Variance
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const reconciliationForm = document.getElementById('reconForm');
const reconciliationKey = document.getElementById('recon-idempotency-key');
const reconciliationStatus = document.getElementById('recon-submission-status');
const reconciliationSubmit = document.getElementById('recon-submit');
const reconciliationStorage = 'coop.bank-reconciliation.pending.v1.staff-<?php echo (int) ($_SESSION['SESS_MEMBER_ID'] ?? 0); ?>';
const reconciliationFields = ['bank_account_id','periodid','reconciliation_date','book_balance','bank_statement_balance','outstanding_deposits','outstanding_withdrawals','bank_charges','bank_interest','notes','idempotency_key'];
let reconciliationPending = null;
let reconciliationBusy = false;
let reconciliationRecoveryAvailable = true;
let reconciliationComplete = false;
let bookBalanceRequest = 0;

function reconciliationReference() {
    if (typeof crypto === 'undefined' || !crypto.getRandomValues) throw new Error('Secure submission references are unavailable. Reload this page.');
    const bytes = new Uint8Array(24); crypto.getRandomValues(bytes);
    return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
}
function reconciliationSnapshot() {
    const data = new FormData(reconciliationForm);
    const snapshot = {};
    for (const field of reconciliationFields) {
        const value = data.get(field);
        if (typeof value !== 'string') throw new Error('Complete all reconciliation fields before saving.');
        snapshot[field] = value;
    }
    return snapshot;
}
function reconciliationMinor(value) {
    const match = /^(-?)([0-9]{1,13})(?:\.([0-9]{1,2}))?$/.exec(value);
    if (!match) return null;
    const amount = BigInt(match[2]) * 100n + BigInt((match[3] || '').padEnd(2, '0'));
    return match[1] === '-' ? -amount : amount;
}
function reconciliationMoney(value) {
    const absolute = value < 0n ? -value : value;
    return (value < 0n ? '-' : '') + (absolute / 100n).toString() + '.' + (absolute % 100n).toString().padStart(2, '0');
}
try {
    const stored = sessionStorage.getItem(reconciliationStorage);
    if (stored) {
        const pending = JSON.parse(stored);
        if (!pending || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/.test(pending.idempotency_key || '') ||
            Object.keys(pending).length !== reconciliationFields.length || reconciliationFields.some(field => typeof pending[field] !== 'string')) throw new Error('Saved submission reference needs review.');
        reconciliationPending = pending;
        for (const field of reconciliationFields) reconciliationForm.elements.namedItem(field).value = pending[field];
        reconciliationStatus.textContent = 'An interrupted submission was restored. Retry with these same details, or check history before starting a new reconciliation.';
    }
} catch (error) {
    reconciliationRecoveryAvailable = false;
    reconciliationSubmit.disabled = true;
    reconciliationStatus.textContent = 'Submission recovery is unavailable. Keep this page open and ask an administrator to check the previous reconciliation before starting again.';
}

document.getElementById('bankAccountSelect').addEventListener('change', fetchBookBalance);
document.getElementById('periodSelect').addEventListener('change', fetchBookBalance);
async function fetchBookBalance() {
    if (reconciliationPending) return;
    const accountId = document.getElementById('bankAccountSelect').value;
    const periodid = document.getElementById('periodSelect').value;
    const request = ++bookBalanceRequest;
    if (!accountId || !periodid) return;
    try {
        const response = await fetch(`api/get_book_balance.php?account_id=${encodeURIComponent(accountId)}&periodid=${encodeURIComponent(periodid)}`);
        const data = await response.json();
        if (request !== bookBalanceRequest || reconciliationPending) return;
        if (!response.ok || !data.success || typeof data.balance_decimal !== 'string' || reconciliationMinor(data.balance_decimal) === null) throw new Error('Book balance is unavailable.');
        document.getElementById('bookBalance').value = data.balance_decimal;
        calculateReconciliation();
    } catch (error) {
        if (request === bookBalanceRequest && !reconciliationPending) {
            document.getElementById('bookBalance').value = '';
            reconciliationStatus.textContent = 'Book balance could not be loaded. Select the account and period again before saving.';
        }
    }
}
function calculateReconciliation() {
    const ids = ['bankBalance','outstandingDeposits','outstandingWithdrawals','bookBalance','bankCharges','bankInterest'];
    const amounts = ids.map(id => reconciliationMinor(document.getElementById(id).value));
    if (amounts.some(value => value === null)) {
        document.getElementById('varianceStatus').textContent = 'Enter valid amounts for a preview. The server confirms the saved result.';
        return;
    }
    const [bank, deposits, withdrawals, book, charges, interest] = amounts;
    for (const [id, amount] of [['displayBankBalance',bank],['displayOutDeposits',deposits],['displayOutWithdrawals',withdrawals],['displayBookBalance',book],['displayBankCharges',charges],['displayBankInterest',interest]]) document.getElementById(id).textContent = '₦' + reconciliationMoney(amount);
    const adjustedBank = bank + deposits - withdrawals;
    const adjustedBook = book + interest - charges;
    const variance = adjustedBank - adjustedBook;
    document.getElementById('adjustedBankBalance').textContent = '₦' + reconciliationMoney(adjustedBank);
    document.getElementById('adjustedBookBalance').textContent = '₦' + reconciliationMoney(adjustedBook);
    document.getElementById('variance').textContent = '₦' + reconciliationMoney(variance < 0n ? -variance : variance);
    document.getElementById('varianceDisplay').className = 'mt-4 p-4 rounded-lg ' + (variance === 0n ? 'bg-green-100 border-2 border-green-500' : 'bg-red-100 border-2 border-red-500');
    document.getElementById('varianceStatus').textContent = variance === 0n ? 'Preview is balanced. The server confirms the result when saved.' : 'Preview has a variance. Review the amounts before saving.';
}
document.querySelectorAll('.recon-input').forEach(input => input.addEventListener('input', calculateReconciliation));
document.getElementById('bankBalance').addEventListener('input', calculateReconciliation);
async function resetForm() {
    if (reconciliationBusy) return;
    if (reconciliationPending || !reconciliationRecoveryAvailable) {
        const choice = await Swal.fire({title:'Start a new reconciliation?',text:'The interrupted save may already be complete. Check history before starting a separate reconciliation.',showCancelButton:true,confirmButtonText:'Start New Reconciliation'});
        if (!choice.isConfirmed) return;
    }
    try {
        const key = reconciliationReference();
        sessionStorage.removeItem(reconciliationStorage);
        reconciliationPending = null; reconciliationRecoveryAvailable = true; reconciliationComplete = false; bookBalanceRequest++;
        reconciliationForm.reset(); reconciliationKey.value = key; reconciliationSubmit.disabled = false;
        reconciliationStatus.textContent = 'New reconciliation reference ready. Enter the new details.';
        calculateReconciliation();
    } catch (error) { reconciliationRecoveryAvailable = false; reconciliationSubmit.disabled = true; reconciliationStatus.textContent = error.message; }
}
reconciliationForm.addEventListener('submit', async function(event) {
    event.preventDefault();
    if (reconciliationBusy || reconciliationComplete || reconciliationSubmit.disabled) return;
    let timeout = null;
    try {
        const snapshot = reconciliationSnapshot();
        if (reconciliationPending && JSON.stringify(snapshot) !== JSON.stringify(reconciliationPending)) throw new Error('The interrupted submission has different details. Restore its original details or use Start New Reconciliation after checking history.');
        if (!/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/.test(snapshot.idempotency_key)) throw new Error('Reload the page to obtain a valid submission reference.');
        // Persist before sending so refresh or an uncertain response retains the same command and exact payload.
        try { sessionStorage.setItem(reconciliationStorage, JSON.stringify(snapshot)); }
        catch (error) { reconciliationRecoveryAvailable = false; throw new Error('Submission recovery could not be saved. No request was sent.'); }
        reconciliationPending = snapshot; reconciliationBusy = true; reconciliationSubmit.disabled = true;
        reconciliationStatus.textContent = 'Saving with this submission reference. Keep this page open.';
        const body = new FormData(); for (const field of reconciliationFields) body.append(field, snapshot[field]);
        const controller = new AbortController();
        timeout = setTimeout(() => controller.abort(), 30000);
        const response = await fetch('api/create_bank_reconciliation.php', {method:'POST',body,signal:controller.signal});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Saving could not be confirmed. Retry with the same reference.');
        reconciliationComplete = true;
        try {
            sessionStorage.removeItem(reconciliationStorage);
            reconciliationPending = null;
            reconciliationKey.value = reconciliationReference();
        } catch (error) { reconciliationRecoveryAvailable = false; }
        reconciliationStatus.textContent = result.replayed ? 'The original reconciliation was confirmed. No adjustments were posted again.' : 'Reconciliation saved and confirmed.';
        await Swal.fire({icon:'success',title:'Reconciliation Confirmed',text:result.replayed ? 'The original saved result was returned. No duplicate charges or interest were posted.' : 'The bank reconciliation has been saved.',confirmButtonColor:'#16a34a'});
        location.reload();
    } catch (error) {
        reconciliationStatus.textContent = (error.name === 'AbortError' ? 'The request timed out; saving may already have completed.' : error.message) + ' The submission reference is retained; check history or retry with the same details.';
        await Swal.fire({icon:'error',title:'Saving Not Confirmed',text:reconciliationStatus.textContent,confirmButtonColor:'#dc2626'});
    } finally {
        if (timeout !== null) clearTimeout(timeout);
        reconciliationBusy = false;
        reconciliationSubmit.disabled = reconciliationComplete || !reconciliationRecoveryAvailable;
    }
});
calculateReconciliation();
</script>

<?php require_once('includes/footer.php'); ?>