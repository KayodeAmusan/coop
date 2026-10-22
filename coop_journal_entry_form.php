<?php

require_once('includes/session_helper.php');
require_once('includes/admin_access_helper.php');
initSession();
if (findAdminAccessError($_SESSION) !== null) {
    header('Location: index.php');
    exit();
}
require_once('Connections/coop.php');
require_once('includes/header.php');

// One submission identity per rendered form; retries retain this key.
$journalIdempotencyKey = bin2hex(random_bytes(24));

// Get periods for dropdown
$periods = [];
$periodQuery = "SELECT id, PayrollPeriod FROM tbpayrollperiods ORDER BY id DESC";
try {
    $stmt = $conn->query($periodQuery);
    $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching periods: " . $e->getMessage());
}

// Get all active accounts for dropdown
$accountsQuery = "SELECT id, account_code, account_name, account_type, normal_balance 
                  FROM coop_accounts 
                  WHERE is_active = TRUE 
                  AND is_control_account = FALSE
                  ORDER BY account_code";
try {
    $stmt = $conn->query($accountsQuery);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching accounts: " . $e->getMessage());
    $accounts = [];
}
?>

<div class="coop-journal-form container mx-auto py-8 max-w-5xl">
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-blue-900">✍️ Manual Journal Entry</h1>
                <p class="text-gray-600 mt-1">Create accounting entries for expenses, adjustments, and other transactions</p>
            </div>
            <a href="coop_journal_entries.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                <i class="fa fa-list mr-1"></i> View All Entries
            </a>
        </div>
    </div>

    <!-- Quick Examples -->
    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded">
        <h3 class="font-bold text-blue-900 mb-2">💡 Common Examples:</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-sm text-blue-800">
            <div><strong>Salary Payment:</strong> DR Salary (6011) | CR Bank (1102)</div>
            <div><strong>Utility Bill:</strong> DR Office Exp (6008) | CR Bank (1102)</div>
            <div><strong>Asset Purchase:</strong> DR Asset (12XX) | CR Bank (1102)</div>
        </div>
    </div>

    <!-- Journal Entry Form -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <form id="journalEntryForm">
            <input type="hidden" id="journal-idempotency-key" name="idempotency_key" value="<?= htmlspecialchars($journalIdempotencyKey, ENT_QUOTES, 'UTF-8') ?>">
            <p id="journal-security-status" class="text-sm text-red-700 mb-4" role="status"></p>
            <!-- Entry Header -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 pb-6 border-b">
                <div>
                    <label for="periodid" class="block text-sm font-semibold text-gray-700 mb-2">
                        Period <span class="text-red-500">*</span>
                    </label>
                    <select name="periodid" id="periodid" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500" required>
                        <option value="">-- Select Period --</option>
                        <?php foreach ($periods as $period): ?>
                            <option value="<?php echo $period['id']; ?>">
                                <?php echo htmlspecialchars($period['PayrollPeriod']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="entry_date" class="block text-sm font-semibold text-gray-700 mb-2">
                        Entry Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="entry_date" id="entry_date" value="<?php echo date('Y-m-d'); ?>" 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500" required>
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-semibold text-gray-700 mb-2">
                        Description <span class="text-red-500">*</span>
                    </label>
                    <textarea name="description" id="description" rows="2" 
                              class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500" 
                              placeholder="E.g., Monthly salary payment for October 2024"
                              required></textarea>
                </div>

                <div>
                    <label for="entry_type" class="block text-sm font-semibold text-gray-700 mb-2">
                        Entry Type
                    </label>
                    <select name="entry_type" id="entry_type" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500">
                        <option value="manual">Manual</option>
                        <option value="adjustment">Adjustment</option>
                        <option value="system">System</option>
                    </select>
                </div>

                <div>
                    <label for="source_document" class="block text-sm font-semibold text-gray-700 mb-2">
                        Source Document
                    </label>
                    <input type="text" name="source_document" id="source_document" 
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500"
                           placeholder="E.g., Invoice #123, Receipt #456">
                </div>
            </div>

            <!-- Journal Entry Lines -->
            <div class="mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-900">Journal Entry Lines</h3>
                    <div class="text-sm text-gray-600">
                        <span class="mr-4">Total Debits: <span id="totalDebits" class="font-bold text-blue-900">₦0.00</span></span>
                        <span>Total Credits: <span id="totalCredits" class="font-bold text-purple-900">₦0.00</span></span>
                    </div>
                </div>

                <div id="journalLines">
                    <!-- Line 1 (Debit) -->
                    <div class="grid grid-cols-12 gap-2 mb-3 items-end journal-line" data-line="1">
                        <div class="col-span-1 text-center font-bold text-gray-700">1</div>
                        <div class="col-span-5">
                            <label class="block text-xs text-gray-600 mb-1">Account</label>
                            <select name="lines[0][account_id]" aria-label="Journal account" class="w-full border rounded px-2 py-2 text-sm account-select" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>" 
                                            data-type="<?php echo $account['account_type']; ?>"
                                            data-balance="<?php echo $account['normal_balance']; ?>">
                                        <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs text-gray-600 mb-1">Debit (₦)</label>
                            <input type="number" name="lines[0][debit]" step="0.01" min="0" 
                                   aria-label="Debit amount in naira" class="w-full border rounded px-2 py-2 text-sm text-right debit-input"
                                   placeholder="0.00">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs text-gray-600 mb-1">Credit (₦)</label>
                            <input type="number" name="lines[0][credit]" step="0.01" min="0" 
                                   aria-label="Credit amount in naira" class="w-full border rounded px-2 py-2 text-sm text-right credit-input"
                                   placeholder="0.00">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs text-gray-600 mb-1">Description</label>
                            <input type="text" name="lines[0][description]" aria-label="Journal line description"
                                   class="w-full border rounded px-2 py-2 text-sm"
                                   placeholder="Optional">
                        </div>
                    </div>

                    <!-- Line 2 (Credit) -->
                    <div class="grid grid-cols-12 gap-2 mb-3 items-end journal-line" data-line="2">
                        <div class="col-span-1 text-center font-bold text-gray-700">2</div>
                        <div class="col-span-5">
                            <select name="lines[1][account_id]" aria-label="Journal account" class="w-full border rounded px-2 py-2 text-sm account-select" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>">
                                        <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <input type="number" name="lines[1][debit]" step="0.01" min="0" 
                                   aria-label="Debit amount in naira" class="w-full border rounded px-2 py-2 text-sm text-right debit-input"
                                   placeholder="0.00">
                        </div>
                        <div class="col-span-2">
                            <input type="number" name="lines[1][credit]" step="0.01" min="0" 
                                   aria-label="Credit amount in naira" class="w-full border rounded px-2 py-2 text-sm text-right credit-input"
                                   placeholder="0.00">
                        </div>
                        <div class="col-span-2">
                            <input type="text" name="lines[1][description]" aria-label="Journal line description"
                                   class="w-full border rounded px-2 py-2 text-sm"
                                   placeholder="Optional">
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center mt-4">
                    <button type="button" onclick="addLine()" class="text-blue-600 hover:text-blue-800 text-sm">
                        <i class="fa fa-plus-circle mr-1"></i> Add Another Line
                    </button>
                    
                    <div class="text-right">
                        <div id="balanceStatus" role="status" aria-live="polite" class="text-sm mb-2"></div>
                        <div class="text-lg font-bold" id="difference"></div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-4 justify-end">
                <button type="button" onclick="resetForm()" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg">
                    <i class="fa fa-redo mr-1"></i> Reset
                </button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                    <i class="fa fa-save mr-1"></i> Review & Post Entry
                </button>
            </div>
        </form>
    </div>

    <!-- Quick Reference -->
    <div class="mt-6 bg-gray-50 rounded-lg p-4">
        <h4 class="font-bold text-gray-900 mb-2">📖 Quick Reference - Common Account Codes</h4>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 text-xs">
            <div><strong>1102</strong> - Bank Main Account</div>
            <div><strong>1110</strong> - Member Loans</div>
            <div><strong>6011</strong> - Salary Cost</div>
            <div><strong>6002</strong> - Printing & Stationery</div>
            <div><strong>6003</strong> - Telephone</div>
            <div><strong>6004</strong> - Internet Cost</div>
            <div><strong>6008</strong> - Office Expenses</div>
            <div><strong>6009</strong> - Bank Charges</div>
            <div><strong>6010</strong> - Fueling</div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let lineCount = 2;

let journalSubmitting = false;
const MAX_KOBO = 9007199254740991n;
function parseJournalKobo(value) {
    const text = String(value ?? '').trim();
    if (text === '') return 0n;
    if (!/^\d+(?:\.\d{1,2})?$/.test(text)) throw new Error('Use a non-negative amount with at most two decimal places, without commas.');
    const [whole, fraction = ''] = text.split('.');
    const kobo = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
    if (kobo > MAX_KOBO) throw new Error('Amount is too large.');
    return kobo;
}
function formatJournalKobo(kobo) {
    const amount = kobo < 0n ? -kobo : kobo;
    const whole = (amount / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (kobo < 0n ? '-' : '') + '₦' + whole + '.' + (amount % 100n).toString().padStart(2, '0');
}
function journalTotals() {
    let debits = 0n, credits = 0n;
    document.querySelectorAll('.debit-input').forEach(input => { debits += parseJournalKobo(input.value); });
    document.querySelectorAll('.credit-input').forEach(input => { credits += parseJournalKobo(input.value); });
    if (debits > MAX_KOBO || credits > MAX_KOBO) throw new Error('The journal total is too large.');
    return {debits, credits};
}
function calculateTotals() {
    const diffElement = document.getElementById('difference');
    const statusElement = document.getElementById('balanceStatus');
    try {
        const {debits, credits} = journalTotals();
        document.getElementById('totalDebits').textContent = formatJournalKobo(debits);
        document.getElementById('totalCredits').textContent = formatJournalKobo(credits);
        const difference = debits - credits;
        const balanced = difference === 0n && debits > 0n;
        diffElement.className = 'text-lg font-bold ' + (balanced ? 'text-green-700' : 'text-red-700');
        diffElement.textContent = balanced ? '✓ Balanced' : difference === 0n ? 'Enter debit and credit amounts' : 'Out of balance: ' + formatJournalKobo(difference < 0n ? -difference : difference);
        statusElement.textContent = balanced ? 'Debits equal credits. Review the accounts before posting.' : difference > 0n ? 'Need more credits' : difference < 0n ? 'Need more debits' : 'An empty journal cannot be posted.';
    } catch (error) {
        document.getElementById('totalDebits').textContent = 'Check amounts';
        document.getElementById('totalCredits').textContent = 'Check amounts';
        diffElement.textContent = 'Invalid amount';
        diffElement.className = 'text-lg font-bold text-red-700';
        statusElement.textContent = error.message;
    }
}
function generateJournalKey() {
    if (!globalThis.crypto || typeof globalThis.crypto.getRandomValues !== 'function') return null;
    const bytes = new Uint8Array(24);
    try { globalThis.crypto.getRandomValues(bytes); } catch (_) { return null; }
    return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
}
function requireJournalReload() {
    document.getElementById('journal-idempotency-key').value = '';
    document.getElementById('journal-security-status').textContent = 'Reload this page before starting another journal. A secure new submission key could not be created.';
    document.querySelector('#journalEntryForm [type="submit"]').disabled = true;
}

// Add new line
function addLine() {
    lineCount++;
    const container = document.getElementById('journalLines');
    const newLine = document.createElement('div');
    newLine.className = 'grid grid-cols-12 gap-2 mb-3 items-end journal-line';
    newLine.setAttribute('data-line', lineCount);
    
    newLine.innerHTML = `
        <div class="col-span-1 text-center font-bold text-gray-700">${lineCount}</div>
        <div class="col-span-5">
            <select name="lines[${lineCount - 1}][account_id]" aria-label="Journal account" class="w-full border rounded px-2 py-2 text-sm account-select" required>
                <option value="">-- Select Account --</option>
                <?php foreach ($accounts as $account): ?>
                    <option value="<?php echo $account['id']; ?>">
                        <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-span-2">
            <input type="number" name="lines[${lineCount - 1}][debit]" step="0.01" min="0" 
                   aria-label="Debit amount in naira" class="w-full border rounded px-2 py-2 text-sm text-right debit-input"
                   placeholder="0.00">
        </div>
        <div class="col-span-2">
            <input type="number" name="lines[${lineCount - 1}][credit]" step="0.01" min="0" 
                   aria-label="Credit amount in naira" class="w-full border rounded px-2 py-2 text-sm text-right credit-input"
                   placeholder="0.00">
        </div>
        <div class="col-span-1">
            <input type="text" name="lines[${lineCount - 1}][description]" aria-label="Journal line description"
                   class="w-full border rounded px-2 py-2 text-sm"
                   placeholder="Optional">
        </div>
        <div class="col-span-1">
            <button type="button" onclick="removeLine(${lineCount})" aria-label="Remove journal line ${lineCount}"
                    class="w-full bg-red-500 hover:bg-red-600 text-white px-2 py-2 rounded">
                <i class="fa fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(newLine);
    
    // Attach event listeners to new inputs
    newLine.querySelectorAll('.debit-input, .credit-input').forEach(input => {
        input.addEventListener('input', calculateTotals);
        input.addEventListener('input', enforceDebitOrCredit);
    });
}

// Remove line
function removeLine(lineNumber) {
    const line = document.querySelector(`[data-line="${lineNumber}"]`);
    if (line) {
        line.remove();
        calculateTotals();
    }
}

// Ensure only debit OR credit is filled (not both)
function enforceDebitOrCredit(e) {
    const row = e.target.closest('.journal-line');
    if (!row) return;
    
    const debitInput = row.querySelector('.debit-input');
    const creditInput = row.querySelector('.credit-input');
    
    let amount;
    try { amount = parseJournalKobo(e.target.value); } catch (_) { return; }
    if (e.target.classList.contains('debit-input') && amount > 0n) {
        creditInput.value = '';
    } else if (e.target.classList.contains('credit-input') && amount > 0n) {
        debitInput.value = '';
    }
    calculateTotals();
}

// A deliberate reset starts a new operation; ordinary retries keep the existing key.
function resetForm() {
    if (journalSubmitting) return false;
    const newKey = generateJournalKey();
    if (!newKey) { requireJournalReload(); return false; }
    document.getElementById('journalEntryForm').reset();
    document.getElementById('journal-idempotency-key').value = newKey;
    document.getElementById('journalLines').querySelectorAll('.journal-line').forEach((line, index) => { if (index > 1) line.remove(); });
    lineCount = 2;
    calculateTotals();
    return true;
}
async function submitJournalEntry(event) {
    event.preventDefault();
    if (journalSubmitting) return;
    const form = document.getElementById('journalEntryForm');
    const submitButton = document.querySelector('#journalEntryForm [type="submit"]');
    let totals;
    try {
        totals = journalTotals();
        if (totals.debits !== totals.credits) throw new Error('Total debits must exactly equal total credits. Even a one-kobo difference must be corrected.');
        if (totals.debits === 0n) throw new Error('Enter at least one debit and one credit amount.');
        if (!/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/.test(document.getElementById('journal-idempotency-key').value)) throw new Error('Reload this page to obtain a secure submission key.');
    } catch (error) {
        await Swal.fire({icon:'error', title:'Check journal entry', text:error.message});
        return;
    }
    const formData = new FormData(form);
    const period = document.getElementById('periodid');
    const lines = Array.from(document.querySelectorAll('.journal-line')).map((line, index) => {
        const account = line.querySelector('.account-select');
        return 'Line ' + (index + 1) + ': ' + (account.selectedOptions[0]?.textContent || '') + '; debit ' + formatJournalKobo(parseJournalKobo(line.querySelector('.debit-input').value)) + '; credit ' + formatJournalKobo(parseJournalKobo(line.querySelector('.credit-input').value));
    });
    journalSubmitting = true;
    submitButton.disabled = true;
    try {
        const confirmation = await Swal.fire({
            icon:'question', title:'Review journal before posting',
            text:'Period: ' + (period.selectedOptions[0]?.textContent || '') + '\nDate: ' + document.getElementById('entry_date').value + '\nDescription: ' + document.getElementById('description').value + '\nEntry type: ' + document.getElementById('entry_type').value + '\nSource document: ' + (document.getElementById('source_document').value || 'None provided') + '\n' + lines.join('\n') + '\nBalanced debits and credits: ' + formatJournalKobo(totals.debits) + '. Posting changes account records.',
            showCancelButton:true, confirmButtonText:'Post journal entry', cancelButtonText:'Return to edit'
        });
        if (!confirmation.isConfirmed) return;
        Swal.fire({title:'Posting journal entry',text:'Waiting for the server confirmation.',allowOutsideClick:false,allowEscapeKey:false,didOpen:()=>Swal.showLoading()});
        const response = await fetch('api/create_journal_entry.php', {method:'POST',body:formData});
        const result = await response.json();
        if (result.success && response.ok) {
            let displayedAmount;
            try { if (result.total_amount === null || result.total_amount === undefined || result.total_amount === '') throw new Error('Missing amount'); displayedAmount = formatJournalKobo(parseJournalKobo(result.total_amount)); } catch (_) { displayedAmount = 'See the journal record'; }
            journalSubmitting = false;
            resetForm();
            await Swal.fire({icon:'success',title:'Journal entry posted',text:'Entry number: ' + String(result.entry_number ?? '') + '. Total amount: ' + displayedAmount + '. The server confirmed this entry.'});
        } else {
            await Swal.fire({icon:'error',title:response.status === 409 ? 'Submission needs review' : 'Journal not confirmed',text:result.error || result.message || 'The server did not confirm this journal. Check the entry list before retrying.'});
        }
    } catch (_) {
        await Swal.fire({icon:'error',title:'Posting not confirmed',text:'The connection ended before confirmation. Check the journal entry list. If you retry this unchanged form, it will use the same submission key to prevent a duplicate.'});
    } finally {
        journalSubmitting = false;
        submitButton.disabled = !document.getElementById('journal-idempotency-key').value;
    }
}
document.getElementById('journalEntryForm').addEventListener('submit', submitJournalEntry);

// Attach event listeners on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.debit-input, .credit-input').forEach(input => {
        input.addEventListener('input', calculateTotals);
        input.addEventListener('input', enforceDebitOrCredit);
    });
    calculateTotals();
});
</script>

<?php require_once('includes/footer.php'); ?>
