<?php
$pageTitle = 'OOUTH COOP - Loan Request Admin';
require_once('includes/session_helper.php');
require_once('includes/admin_access_helper.php');
initSession();
if (findAdminAccessError($_SESSION) !== null) {
    header('Location: index.php');
    exit();
}
require_once('Connections/coop.php');
include 'includes/header.php';
?>
<section class="coop-loan-admin max-w-7xl mx-auto" aria-labelledby="loan-admin-title">
    <!-- Page Header -->
    <div class="mb-8 fade-in">
        <div class="flex items-center justify-between">
            <div>
                <h1 id="loan-admin-title" class="text-3xl font-bold text-gray-900 mb-2">
                    <i class="fas fa-money-bill-wave text-blue-600 mr-3"></i>Loan Request Administration
                </h1>
                <p class="text-gray-600">Review applications and staff-reviewed signed guarantor approvals. Loan approval and payment are separate steps.</p>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="bg-white rounded-xl shadow-lg mb-8 fade-in">
        <div class="border-b border-gray-200">
            <nav class="coop-loan-tabs flex flex-wrap -mb-px" role="tablist" aria-label="Loan administration sections">
                <button type="button" onclick="showTab('limits')" id="tab-limits" role="tab" aria-controls="content-limits" aria-selected="true" tabindex="0"
                    class="tab-button active py-4 px-6 text-sm font-medium text-blue-600 border-b-2 border-blue-600">
                    <i class="fas fa-chart-line mr-2"></i>Loan Limits
                </button>
                <button type="button" onclick="showTab('approvals')" id="tab-approvals" role="tab" aria-controls="content-approvals" aria-selected="false" tabindex="-1"
                    class="tab-button py-4 px-6 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">
                    <i class="fas fa-check-circle mr-2"></i>Pending Approvals
                </button>
                <button type="button" onclick="showTab('outstanding')" id="tab-outstanding" role="tab" aria-controls="content-outstanding" aria-selected="false" tabindex="-1"
                    class="tab-button py-4 px-6 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">
                    <i class="fas fa-clock mr-2"></i>Outstanding Loans
                </button>
                <button type="button" onclick="showTab('manual')" id="tab-manual" role="tab" aria-controls="content-manual" aria-selected="false" tabindex="-1"
                    class="tab-button py-4 px-6 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">
                    <i class="fas fa-plus-circle mr-2"></i>Manual Loan
                </button>
            </nav>
        </div>
    </div>

    <!-- Loan Limits Tab -->
    <div id="content-limits" role="tabpanel" aria-labelledby="tab-limits" tabindex="0" class="tab-content">
        <!-- Action Bar -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8 fade-in">
            <div class="flex justify-between items-center">
                <div class="flex items-center">
                    <i class="fas fa-chart-line text-blue-600 mr-3"></i>
                    <h3 class="text-lg font-semibold text-gray-900">Monthly Loan Limits</h3>
                </div>
                <button type="button" onclick="openAddLimitModal()"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                    <i class="fas fa-plus mr-2"></i>Set New Limit
                </button>
            </div>
        </div>

        <!-- Limits Table -->
        <div class="bg-white rounded-xl shadow-lg fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Period Limits</h3>
            </div>
            <div class="p-6">
                <div id="limits-table-container" aria-live="polite">
                    <div class="text-center py-8">
                        <i class="fas fa-spinner fa-spin text-blue-600 text-2xl mb-2"></i>
                        <p class="text-gray-500">Loading limits...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Approvals Tab -->
    <div id="content-approvals" role="tabpanel" aria-labelledby="tab-approvals" tabindex="0" class="tab-content hidden">
        <!-- Summary Cards -->
        <div id="summary-cards" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Cards will be populated by JavaScript -->
        </div>

        <!-- Period Summary -->
        <div class="bg-white rounded-xl shadow-lg mb-8 fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-chart-bar text-blue-600 mr-2"></i>Period Summary
                </h3>
            </div>
            <div class="p-6">
                <div id="period-summary-container" aria-live="polite">
                    <div class="text-center py-8">
                        <i class="fas fa-spinner fa-spin text-blue-600 text-2xl mb-2"></i>
                        <p class="text-gray-500">Loading summary...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Requests Table -->
        <div class="bg-white rounded-xl shadow-lg fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Pending Loan Requests</h3>
            </div>
            <div class="p-6">
                <div class="coop-request-filters">
                    <div><label for="loan-request-search">Find an application in this list</label><input id="loan-request-search" type="search" placeholder="Member name or period" oninput="filterPendingApprovals()" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></div>
                    <div><label for="loan-request-status">Application status</label><select id="loan-request-status" onchange="filterPendingApprovals()" class="w-full px-3 py-2 border border-gray-300 rounded-lg"><option value="">All listed statuses</option><option value="submitted">Submitted</option><option value="fully_guaranteed">Signed approvals ready</option></select></div>
                </div><p class="text-sm text-gray-600 mb-3">Scroll inside the records to see every column on a small screen.</p>
                <div id="approvals-table-container" aria-live="polite">
                    <div class="text-center py-8">
                        <i class="fas fa-spinner fa-spin text-blue-600 text-2xl mb-2"></i>
                        <p class="text-gray-500">Loading requests...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Manual Loan Tab -->
    <div id="content-manual" role="tabpanel" aria-labelledby="tab-manual" tabindex="0" class="tab-content hidden">
        <div class="bg-white rounded-xl shadow-lg fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-plus-circle text-green-600 mr-2"></i>Create Loan Manually
                </h3>
            </div>
            <div class="p-6">
                <p id="loan-product-setup-required" role="status" class="mb-4 p-4 bg-amber-50 text-gray-900">Loan product configuration is required before new applications can be created. <a href="loan_product_setup.php" class="underline">Open product drafts and calculation preview</a>. Publication is still being implemented. Existing applications remain available for review.</p>
                <form id="manual-loan-form" class="space-y-6" aria-describedby="loan-product-setup-required">
                    <fieldset disabled aria-describedby="loan-product-setup-required" class="space-y-6"><legend class="sr-only">New loan application unavailable until product setup is complete</legend>
                    <!-- Member Search -->
                    <div>
                        <label for="manual-member-coop-id" class="block text-sm font-medium text-gray-700 mb-2">
                            Member CoopID <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="text" id="manual-member-coop-id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Enter or search CoopID/Name" required
                                onkeyup="handleMemberSearchInput(event)">
                            <button type="button" onclick="searchMemberForManualLoan()"
                                class="absolute right-2 top-2 px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                        <!-- Search Results Dropdown -->
                        <div id="manual-member-results"
                            class="mt-2 hidden border border-gray-300 rounded-lg bg-white shadow-lg max-h-60 overflow-y-auto z-10">
                            <!-- Search results will be displayed here -->
                        </div>
                        <!-- Selected Member Info -->
                        <div id="manual-member-info" class="mt-2 hidden p-3 bg-gray-50 rounded-lg">
                            <!-- Selected member info will be displayed here -->
                        </div>
                    </div>

                    <!-- Period Selection -->
                    <div>
                        <label for="manual-period-id" class="block text-sm font-medium text-gray-700 mb-2">
                            Payroll Period <span class="text-red-500">*</span>
                        </label>
                        <select id="manual-period-id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                            required>
                            <option value="">Loading periods...</option>
                        </select>
                    </div>

                    <!-- Loan Amount -->
                    <div>
                        <label for="manual-requested-amount" class="block text-sm font-medium text-gray-700 mb-2">
                            Requested Amount (₦) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" id="manual-requested-amount" step="0.01" min="0"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Enter loan amount" required>
                    </div>

                    <!-- Status Selection -->
                    <div>
                        <label for="manual-status" class="block text-sm font-medium text-gray-700 mb-2">
                            Initial Status <span class="text-red-500">*</span>
                        </label>
                        <select id="manual-status"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                            required>
                            <option value="draft">Draft</option>
                            <option value="pending_guarantors">Request guarantor consent</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">
                            <strong>Draft:</strong> Created but not submitted<br>
                            <strong>Request guarantor evidence:</strong> Signed approval must be uploaded and accepted by staff. An online response alone is insufficient; loan approval is a separate step.
                        </p>
                    </div>

                    <!-- Guarantors Section -->
                    <div id="manual-guarantors-section">
                        <p class="block text-sm font-medium text-gray-700 mb-2">Guarantors (2 required)</p>

                        <!-- Guarantor 1 -->
                        <div class="mb-4">
                            <label for="manual-guarantor-1-coop-id" class="block text-xs font-medium text-gray-600 mb-1">Guarantor 1</label>
                            <div class="relative">
                                <input type="text" id="manual-guarantor-1-coop-id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Enter or search CoopID/Name"
                                    onkeyup="handleGuarantorSearchInput(event, 1)">
                                <button type="button" onclick="searchGuarantorForManualLoan(1)"
                                    class="absolute right-2 top-2 px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                            <div id="manual-guarantor-1-results"
                                class="mt-1 hidden border border-gray-300 rounded-lg bg-white shadow-lg max-h-48 overflow-y-auto z-10">
                                <!-- Search results will be displayed here -->
                            </div>
                            <div id="manual-guarantor-1-info" class="mt-1 hidden p-2 bg-gray-50 rounded text-xs">
                                <!-- Selected guarantor info will be displayed here -->
                            </div>
                        </div>

                        <!-- Guarantor 2 -->
                        <div>
                            <label for="manual-guarantor-2-coop-id" class="block text-xs font-medium text-gray-600 mb-1">Guarantor 2</label>
                            <div class="relative">
                                <input type="text" id="manual-guarantor-2-coop-id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Enter or search CoopID/Name"
                                    onkeyup="handleGuarantorSearchInput(event, 2)">
                                <button type="button" onclick="searchGuarantorForManualLoan(2)"
                                    class="absolute right-2 top-2 px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                            <div id="manual-guarantor-2-results"
                                class="mt-1 hidden border border-gray-300 rounded-lg bg-white shadow-lg max-h-48 overflow-y-auto z-10">
                                <!-- Search results will be displayed here -->
                            </div>
                            <div id="manual-guarantor-2-info" class="mt-1 hidden p-2 bg-gray-50 rounded text-xs">
                                <!-- Selected guarantor info will be displayed here -->
                            </div>
                        </div>
                    </div>

                    <!-- Admin Notes -->
                    <div>
                        <label for="manual-admin-notes" class="block text-sm font-medium text-gray-700 mb-2">Admin Notes (Optional)</label>
                        <textarea id="manual-admin-notes" rows="3"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Add any notes about this loan..."></textarea>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="resetManualLoanForm()"
                            class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                            Reset
                        </button>
                        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            <i class="fas fa-save mr-2"></i>Create Loan
                        </button>
                    </div>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>

    <!-- Outstanding Loans Tab -->
    <div id="content-outstanding" role="tabpanel" aria-labelledby="tab-outstanding" tabindex="0" class="tab-content hidden">
        <div class="bg-white rounded-xl shadow-lg fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-clock text-yellow-600 mr-2"></i>Outstanding Loans
                    </h3>
                    <button type="button" onclick="loadOutstandingLoans()"
                        class="px-3 py-1 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                        <i class="fas fa-sync-alt mr-1"></i> Refresh
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div id="outstanding-table-container" aria-live="polite">
                    <div class="text-center py-8">
                        <i class="fas fa-spinner fa-spin text-blue-600 text-2xl mb-2"></i>
                        <p class="text-gray-500">Loading outstanding loans...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Add/Edit Limit Modal -->
<div id="limitModal" role="dialog" aria-modal="true" aria-labelledby="modal-title" tabindex="-1" class="coop-loan-dialog fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-xl font-semibold text-gray-900" id="modal-title">Set Loan Limit</h3>
            </div>
            <div class="px-6 py-4">
                <form id="limitForm">
                    <input type="hidden" id="limit-id">
                    <div class="mb-4">
                        <label for="limit-period-id" class="block text-sm font-medium text-gray-700 mb-2">Payroll Period</label>
                        <select id="limit-period-id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                            required>
                            <option value="">Select Period</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="limit-amount" class="block text-sm font-medium text-gray-700 mb-2">Limit Amount (₦)</label>
                        <input type="number" id="limit-amount" step="0.01" min="0"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                            required>
                    </div>
                    <div class="mb-4">
                        <label for="limit-notes" class="block text-sm font-medium text-gray-700 mb-2">Notes (Optional)</label>
                        <textarea id="limit-notes" rows="3"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"></textarea>
                    </div>

                </form>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeLimitModal()"
                    class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">Cancel</button>
                <button type="button" onclick="saveLimit()"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Import Outstanding Loan Modal -->
<div id="importModal" role="dialog" aria-modal="true" aria-labelledby="import-title" tabindex="-1" class="coop-loan-dialog fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 id="import-title" class="text-xl font-semibold text-gray-900">Review carried-forward balance</h3>
            </div>
            <div class="px-6 py-4">
                <div id="import-details"></div>
                <div class="mt-4">
                    <label for="import-period-id" class="block text-sm font-medium text-gray-700 mb-2">Select Period to Import To</label>
                    <select id="import-period-id"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                        required>
                        <option value="">Loading periods...</option>
                    </select>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeImportModal()"
                    class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">Cancel</button>
                <button type="button" onclick="importOutstandingLoan()"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">View consent requirement</button>
            </div>
        </div>
    </div>
</div>

<!-- Approval Modal -->
<div id="approvalModal" role="dialog" aria-modal="true" aria-labelledby="approval-title" tabindex="-1" class="coop-loan-dialog fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-lg w-full">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 id="approval-title" class="text-xl font-semibold text-gray-900">Review loan approval</h3>
            </div>
            <div class="px-6 py-4">
                <div id="approval-details"></div>
                <div class="mt-4">
                    <label for="approved-amount" class="block text-sm font-medium text-gray-700 mb-2">
                        Approved Amount (₦)
                        <span class="text-xs text-gray-500 font-normal">(The amount covered by the signed approvals. Changing it requires fresh evidence for the new terms.)</span>
                    </label>
                    <input type="number" id="approved-amount" step="0.01" min="0" readonly
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                        placeholder="Enter approved amount">
                    <p class="text-xs text-gray-500 mt-1" id="outstanding-info"></p>
                </div>
                <p class="mt-4 text-sm text-gray-700">Required guarantor invitations need current, clean, staff-accepted signed approvals before loan approval. Approval does not confirm payment.</p>
                <div class="mt-4">
                    <label for="admin-notes" class="block text-sm font-medium text-gray-700 mb-2">Admin Notes (Optional)</label>
                    <textarea id="admin-notes" rows="3"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeApprovalModal()"
                    class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">Cancel</button>
                <button type="button" onclick="approveLoan()"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">Approve loan request</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentLoanRequestId = null;
let apiBaseUrl = 'auth_api/api/admin';
let isAdminPanel = true; // Flag to indicate we're in admin panel

let loanModalReturnFocus = null;
let activeLoanModal = null;
function setLoanModalOpen(id, open) {
    const modal = document.getElementById(id);
    if (open) {
        loanModalReturnFocus = document.activeElement;
        modal.classList.remove('hidden');
        activeLoanModal = modal;
        document.getElementById('main-content').querySelectorAll(':scope > :not(script)').forEach(el => {
            if (el !== modal && !el.contains(modal)) el.setAttribute('inert', '');
        });
        document.getElementById('sidebar').setAttribute('inert', '');
        document.querySelector('body > header').setAttribute('inert', '');
        document.body.classList.add('overflow-hidden');
        modal.querySelector('button, input:not([type="hidden"]), select, textarea')?.focus();
    } else {
        modal.classList.add('hidden');
        if (activeLoanModal !== modal) return;
        activeLoanModal = null;
        document.getElementById('main-content').querySelectorAll(':scope > [inert]').forEach(el => el.removeAttribute('inert'));
        document.getElementById('sidebar').toggleAttribute('inert', window.innerWidth < 1024);
        document.querySelector('body > header').removeAttribute('inert');
        document.body.classList.remove('overflow-hidden');
        loanModalReturnFocus?.focus();
    }
}
// API data is escaped at the HTML boundary. Only local markup fragments are trusted.
const loanHtmlFragment = Symbol('local loan markup');
function trustedLoanHtml(html) { return {[loanHtmlFragment]: html}; }
function escapeLoanText(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
}
function loanHtml(strings, ...values) {
    return strings.reduce((output, text, index) => output + text + (index < values.length ? (values[index] && typeof values[index] === 'object' && loanHtmlFragment in values[index] ? values[index][loanHtmlFragment] : escapeLoanText(values[index])) : ''), '');
}
function bindLoanRows(container, selector, rows, action) {
    container.querySelectorAll(selector).forEach(control => {
        control.addEventListener('click', () => {
            const index = Number(control.getAttribute('data-row-index'));
            if (Number.isInteger(index) && rows[index]) action(rows[index]);
        });
    });
}
// Tab switching
function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.tab-button').forEach(el => {
        el.setAttribute('aria-selected', 'false');
        el.tabIndex = -1;
        el.classList.remove('active', 'text-blue-600', 'border-blue-600');
        el.classList.add('text-gray-500', 'border-transparent');
    });

    document.getElementById(`content-${tab}`).classList.remove('hidden');
    const button = document.getElementById(`tab-${tab}`);
    button.setAttribute('aria-selected', 'true');
    button.tabIndex = 0;
    button.classList.add('active', 'text-blue-600', 'border-blue-600');
    button.classList.remove('text-gray-500', 'border-transparent');

    if (tab === 'limits') {
        loadLimits();
    } else if (tab === 'approvals') {
        loadPendingApprovals();
    } else if (tab === 'outstanding') {
        loadOutstandingLoans();
    } else if (tab === 'manual') {
        loadPeriodsForManualLoan();
    }
}

// Load loan limits
async function loadLimits() {
    try {
        const response = await fetch(`${apiBaseUrl}/loan-limits.php`, {
            credentials: 'include' // Include session cookies
        });
        const result = await response.json();

        if (result.success) {
            displayLimits(result.data);
        } else {
            Swal.fire('Error', result.message, 'error');
        }
    } catch (error) {
        console.error('Error loading limits:', error);
        Swal.fire('Error', 'Failed to load limits', 'error');
    }
}

function displayLimits(limits) {
    const container = document.getElementById('limits-table-container');

    if (!limits || limits.length === 0) {
        container.innerHTML = '<div class="text-center py-8 text-gray-500">No limits set yet</div>';
        return;
    }

    let html =
        '<div class="coop-loan-table overflow-x-auto" role="region" aria-label="Loan records. Scroll horizontally for all columns." tabindex="0"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Limit Amount</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Set By</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>';
    html += '</tr></thead><tbody class="bg-white divide-y divide-gray-200">';

    limits.forEach((limit, index) => {
        html += loanHtml`<tr>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${limit.period_name || 'Period ' + limit.period_id}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">₦${parseFloat(limit.limit_amount).toLocaleString('en-NG', {minimumFractionDigits: 2})}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${limit.set_by}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm">
                <button type="button" data-edit-limit data-row-index="${index}"
                    class="text-blue-600 hover:text-blue-800 mr-3">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
    bindLoanRows(container, '[data-edit-limit]', limits, limit => editLimit(limit.id, limit.period_id, limit.limit_amount, limit.notes));
}

// Load pending approvals
async function loadPendingApprovals() {
    try {
        const container = document.getElementById('approvals-table-container');
        container.innerHTML =
            '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-blue-600 text-2xl mb-2"></i><p class="text-gray-500">Loading requests...</p></div>';

        const response = await fetch(`${apiBaseUrl}/loan-approval.php`, {
            credentials: 'include' // Include session cookies
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();

        if (result.success) {
            if (result.summary) {
                displaySummaryCards(result.summary);
                displayPeriodSummary(result.summary.periods || []);
            }
            displayPendingApprovals(result.data || []);
        } else {
            container.innerHTML = loanHtml`<div class="text-center py-8 text-red-500">Error: ${result.message}</div>`;
            Swal.fire('Error', result.message, 'error');
        }
    } catch (error) {
        console.error('Error loading approvals:', error);
        const container = document.getElementById('approvals-table-container');
        container.innerHTML = loanHtml`<div class="text-center py-8 text-red-500">Failed to load: ${error.message}</div>`;
        Swal.fire('Error', 'Failed to load pending approvals: ' + error.message, 'error');
    }
}

function displaySummaryCards(summary) {
    const container = document.getElementById('summary-cards');

    if (!summary || !summary.periods) {
        container.innerHTML = '<div class="col-span-4 text-center py-4 text-gray-500">No summary data available</div>';
        return;
    }

    const totalSubmitted = summary.total_submitted || 0;
    const totalPendingGuarantor = summary.total_pending_guarantor || 0;

    // Calculate total amounts
    let totalSubmittedAmount = 0;
    let totalPendingGuarantorAmount = 0;

    summary.periods.forEach(period => {
        totalSubmittedAmount += period.submitted_amount || 0;
        totalPendingGuarantorAmount += period.pending_guarantor_amount || 0;
    });

    container.innerHTML = loanHtml`
        <div class="bg-white rounded-xl shadow-lg p-6 border-l-4 border-blue-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">Total Submitted</p>
                    <p class="text-2xl font-bold text-gray-900">${totalSubmitted}</p>
                    <p class="text-sm text-gray-600 mt-1">₦${totalSubmittedAmount.toLocaleString('en-NG', {minimumFractionDigits: 2})}</p>
                </div>
                <div class="bg-blue-100 rounded-full p-3">
                    <i class="fas fa-file-alt text-blue-600 text-2xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-6 border-l-4 border-yellow-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">Pending Guarantor</p>
                    <p class="text-2xl font-bold text-gray-900">${totalPendingGuarantor}</p>
                    <p class="text-sm text-gray-600 mt-1">₦${totalPendingGuarantorAmount.toLocaleString('en-NG', {minimumFractionDigits: 2})}</p>
                </div>
                <div class="bg-yellow-100 rounded-full p-3">
                    <i class="fas fa-user-clock text-yellow-600 text-2xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-6 border-l-4 border-green-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">Total Approved</p>
                    <p class="text-2xl font-bold text-gray-900">${summary.periods.reduce((sum, p) => sum + (p.approved_count || 0), 0)}</p>
                    <p class="text-sm text-gray-600 mt-1">₦${summary.periods.reduce((sum, p) => sum + (p.approved_amount || 0), 0).toLocaleString('en-NG', {minimumFractionDigits: 2})}</p>
                </div>
                <div class="bg-green-100 rounded-full p-3">
                    <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-6 border-l-4 border-purple-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">Total Limit Set</p>
                    <p class="text-2xl font-bold text-gray-900">${summary.periods.filter(p => p.limit_amount).length}</p>
                    <p class="text-sm text-gray-600 mt-1">₦${summary.periods.reduce((sum, p) => sum + (p.limit_amount || 0), 0).toLocaleString('en-NG', {minimumFractionDigits: 2})}</p>
                </div>
                <div class="bg-purple-100 rounded-full p-3">
                    <i class="fas fa-chart-line text-purple-600 text-2xl"></i>
                </div>
            </div>
        </div>
    `;
}

function displayPeriodSummary(periods) {
    const container = document.getElementById('period-summary-container');

    if (!periods || periods.length === 0) {
        container.innerHTML = '<div class="text-center py-8 text-gray-500">No period data available</div>';
        return;
    }

    let html =
        '<div class="coop-loan-table overflow-x-auto" role="region" aria-label="Loan records. Scroll horizontally for all columns." tabindex="0"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Limit</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Submitted</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pending Guarantor</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Approved</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Used</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Remaining</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Usage %</th>';
    html += '</tr></thead><tbody class="bg-white divide-y divide-gray-200">';

    periods.forEach(period => {
        const usagePercentage = period.usage_percentage !== null ? period.usage_percentage.toFixed(1) : 'N/A';
        const usageColor = period.usage_percentage !== null ?
            (period.usage_percentage >= 90 ? 'text-red-600' : period.usage_percentage >= 75 ?
                'text-yellow-600' : 'text-green-600') :
            'text-gray-600';

        html += loanHtml`<tr>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${period.period_name}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                ${period.limit_amount ? '₦' + parseFloat(period.limit_amount).toLocaleString('en-NG', {minimumFractionDigits: 2}) : 'Not set'}
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                ${period.submitted_count} (₦${parseFloat(period.submitted_amount).toLocaleString('en-NG', {minimumFractionDigits: 2})})
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                ${period.pending_guarantor_count} (₦${parseFloat(period.pending_guarantor_amount).toLocaleString('en-NG', {minimumFractionDigits: 2})})
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                ${period.approved_count} (₦${parseFloat(period.approved_amount).toLocaleString('en-NG', {minimumFractionDigits: 2})})
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                ₦${parseFloat(period.total_submitted_and_approved).toLocaleString('en-NG', {minimumFractionDigits: 2})}
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                ${period.remaining_limit !== null 
                    ? '₦' + parseFloat(period.remaining_limit).toLocaleString('en-NG', {minimumFractionDigits: 2})
                    : 'N/A'}
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold ${usageColor}">
                ${usagePercentage}${usagePercentage !== 'N/A' ? '%' : ''}
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
}

let pendingApprovalRows = [];
function displayPendingApprovals(requests) {
    pendingApprovalRows = requests || [];
    filterPendingApprovals();
}
function filterPendingApprovals() {
    const search = (document.getElementById('loan-request-search').value || '').trim().toLowerCase();
    const status = document.getElementById('loan-request-status').value;
    renderPendingApprovals(pendingApprovalRows.filter(req =>
        (!status || req.status === status) &&
        (!search || String(req.requester_name || '').toLowerCase().includes(search) || String(req.period_name || req.period_id || '').toLowerCase().includes(search))
    ));
}
function renderPendingApprovals(requests) {
    const container = document.getElementById('approvals-table-container');

    if (!requests || requests.length === 0) {
        container.innerHTML = '<div class="text-center py-8 text-gray-500">No applications match the current list and filters.</div>';
        return;
    }

    let html =
        '<div class="coop-loan-table overflow-x-auto" role="region" aria-label="Loan records. Scroll horizontally for all columns." tabindex="0"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requester</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Accepted signed proofs</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Guarantor Names</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Submitted</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>';
    html += '</tr></thead><tbody class="bg-white divide-y divide-gray-200">';

    requests.forEach((req, index) => {
        const statusBadge = req.status === 'submitted' ?
            '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">Submitted</span>' :
            '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Fully Guaranteed</span>';

        const guarantorStatus = req.can_approve === true ?
            loanHtml`<span class="text-green-600 font-semibold">${req.approved_guarantors}/${req.total_guarantors}</span>` :
            loanHtml`<span class="text-yellow-600">${req.approved_guarantors}/${req.total_guarantors}</span>`;

        // Build guarantor names display
        let guarantorNamesHtml = '<div class="space-y-1">';
        if (req.guarantors && req.guarantors.length > 0) {
            req.guarantors.forEach((guarantor, index) => {
                const proofLabels = {accepted: 'Signed approval accepted', missing: 'Signed approval missing', outdated: 'Proof is for superseded terms', quarantined: 'Proof quarantined', infected: 'Proof blocked by scanner', pending: 'Proof awaiting staff review', rejected: 'Proof rejected', not_current_invitation: 'Proof is not for the current invitation'};
                const proofStatus = guarantor.signed_approval_status;
                const statusColor = proofStatus === 'accepted' ? 'text-green-600' : ['rejected', 'infected'].includes(proofStatus) ? 'text-red-600' : 'text-yellow-600';
                guarantorNamesHtml += loanHtml`
                    <div class="text-xs">
                        <span class="${statusColor}">${guarantor.name || 'Unknown'}</span>
                        <span class="ml-1">${proofLabels[proofStatus] || 'Signed proof status unavailable'}</span>
                        <span class="text-gray-500 ml-1">(${guarantor.coop_id})</span>
                        <div class="text-gray-500">Online response history: ${guarantor.status || 'not recorded'}; this alone is not signed approval.</div>
                    </div>
                `;            });
        } else {
            guarantorNamesHtml += '<span class="text-gray-400 text-xs">No guarantors</span>';
        }
        guarantorNamesHtml += '</div>';

        const submittedDate = req.submitted_at ?
            new Date(req.submitted_at).toLocaleDateString('en-NG', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }) :
            'N/A';


        html += loanHtml`<tr>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${req.requester_name || 'Unknown'}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">₦${parseFloat(req.requested_amount).toLocaleString('en-NG', {minimumFractionDigits: 2})}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${req.period_name || 'Period ' + req.period_id}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm">${trustedLoanHtml(statusBadge)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm">${trustedLoanHtml(guarantorStatus)}</td>
            <td class="px-6 py-4 text-sm text-gray-700 max-w-xs">${trustedLoanHtml(guarantorNamesHtml)}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${submittedDate}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm">
                <div class="mb-2"><a class="text-blue-700 underline" href="guarantor_evidence_review.php?loan_request_id=${encodeURIComponent(String(req.id))}">Upload or review signed evidence</a></div>
                <button type="button" data-review-approval data-row-index="${index}" ${trustedLoanHtml(req.can_approve === true ? '' : 'disabled aria-disabled="true"')}
                    class="px-3 py-1 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm disabled:opacity-60">
                    <i class="fas fa-check-circle mr-1" aria-hidden="true"></i> Review approval
                </button>
                ${trustedLoanHtml(req.can_approve === true ? '' : loanHtml`<p class="mt-2 text-xs whitespace-normal">${req.approval_block_reason || 'Server approval readiness has not been confirmed.'}</p>`)}
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
    bindLoanRows(container, '[data-review-approval]', requests, req => { if (req.can_approve === true) openApprovalModal(req.id, req.requester_name, req.requested_amount, req.approved_guarantors, req.total_guarantors); });
}

// Modal functions
function openAddLimitModal() {
    document.getElementById('limit-id').value = '';
    document.getElementById('limit-period-id').value = '';
    document.getElementById('limit-amount').value = '';
    document.getElementById('limit-notes').value = '';
    document.getElementById('modal-title').textContent = 'Set Loan Limit';
    loadPeriodsForLimit();
    setLoanModalOpen('limitModal', true);
}

function editLimit(id, periodId, amount, notes) {
    document.getElementById('limit-id').value = id;
    document.getElementById('limit-period-id').value = periodId;
    document.getElementById('limit-amount').value = amount;
    document.getElementById('limit-notes').value = notes || '';
    document.getElementById('modal-title').textContent = 'Edit Loan Limit';
    loadPeriodsForLimit();
    setLoanModalOpen('limitModal', true);
}

function closeLimitModal() {
    setLoanModalOpen('limitModal', false);
}

async function loadPeriodsForLimit() {
    const select = document.getElementById('limit-period-id');
    select.innerHTML = '<option value="">Loading...</option>';

    try {
        const response = await fetch(`${apiBaseUrl}/periods.php`, {
            credentials: 'include' // Include session cookies
        });
        const result = await response.json();

        if (result.success && result.data && result.data.length > 0) {
            select.innerHTML = '<option value="">Select Period</option>';
            result.data.forEach(period => {
                const option = document.createElement('option');
                const periodId = period.id || period.period_id;
                const displayName = period.display_name || period.description || period.PayrollPeriod ||
                    period.name || `Period ${periodId}`;

                option.value = periodId;
                option.textContent = displayName;
                select.appendChild(option);
            });
        } else {
            select.innerHTML = '<option value="">No periods available</option>';
            console.warn('No periods found:', result.message || 'No data returned');
        }
    } catch (error) {
        console.error('Error loading periods:', error);
        select.innerHTML = '<option value="">Error loading periods</option>';
        Swal.fire('Error', 'Failed to load periods: ' + error.message, 'error');
    }
}

async function saveLimit() {
    const id = document.getElementById('limit-id').value;
    const periodId = document.getElementById('limit-period-id').value;
    const amount = document.getElementById('limit-amount').value;
    const notes = document.getElementById('limit-notes').value;

    if (!periodId || !amount) {
        Swal.fire('Error', 'Please fill all required fields', 'error');
        return;
    }

    try {
        const url = id ? `${apiBaseUrl}/loan-limits.php` : `${apiBaseUrl}/loan-limits.php`;
        const method = id ? 'PUT' : 'POST';
        const body = id ? {
            id: parseInt(id),
            limit_amount: parseFloat(amount),
            notes: notes
        } : {
            period_id: parseInt(periodId),
            limit_amount: parseFloat(amount),
            notes: notes
        };

        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include', // Include session cookies
            body: JSON.stringify(body)
        });

        const result = await response.json();

        if (result.success) {
            Swal.fire('Success', result.message, 'success');
            closeLimitModal();
            loadLimits();
        } else {
            Swal.fire('Error', result.message, 'error');
        }
    } catch (error) {
        console.error('Error saving limit:', error);
        Swal.fire('Error', 'Failed to save limit', 'error');
    }
}

let currentRequestedAmount = 0;

function openApprovalModal(id, name, amount, approvedGuarantors, totalGuarantors) {
    currentLoanRequestId = id;
    currentRequestedAmount = parseFloat(amount);
    document.getElementById('admin-notes').value = '';
    document.getElementById('approved-amount').value = amount;
    document.getElementById('outstanding-info').textContent = '';

    const details = document.getElementById('approval-details');
    details.innerHTML = loanHtml`
        <div class="space-y-2">
            <p><strong>Requester:</strong> ${name}</p>
            <p><strong>Requested Amount:</strong> ₦${currentRequestedAmount.toLocaleString('en-NG', {minimumFractionDigits: 2})}</p>
            <p><strong>Guarantors:</strong> ${approvedGuarantors}/${totalGuarantors} current signed approvals accepted by staff</p>
        </div>
    `;

    // Add event listener to calculate outstanding amount
    const approvedAmountInput = document.getElementById('approved-amount');
    approvedAmountInput.oninput = function() {
        const approved = parseFloat(this.value) || 0;
        if (approved > currentRequestedAmount) {
            document.getElementById('outstanding-info').innerHTML =
                '<span class="text-red-600">Amount cannot exceed requested amount</span>';
        } else if (approved > 0 && approved < currentRequestedAmount) {
            const outstanding = currentRequestedAmount - approved;
            document.getElementById('outstanding-info').innerHTML =
                loanHtml`<span class="text-yellow-600">Outstanding: ₦${outstanding.toLocaleString('en-NG', {minimumFractionDigits: 2})} will be available for next period</span>`;
        } else {
            document.getElementById('outstanding-info').textContent = '';
        }
    };

    setLoanModalOpen('approvalModal', true);
}

function closeApprovalModal() {
    setLoanModalOpen('approvalModal', false);
    currentLoanRequestId = null;
    currentRequestedAmount = 0;
    document.getElementById('approved-amount').value = '';
    document.getElementById('outstanding-info').textContent = '';
}

async function approveLoan() {
    if (!currentLoanRequestId) return;

    const adminNotes = document.getElementById('admin-notes').value;
    const approvedAmountInput = document.getElementById('approved-amount').value;
    const approvedAmount = approvedAmountInput ? parseFloat(approvedAmountInput) : null;

    // Validate approved amount
    if (approvedAmount !== null) {
        if (approvedAmount <= 0) {
            Swal.fire('Error', 'Approved amount must be greater than 0', 'error');
            return;
        }
        if (approvedAmount > currentRequestedAmount) {
            Swal.fire('Error', 'Approved amount cannot exceed requested amount', 'error');
            return;
        }
    }

    try {
        const requestBody = {
            loan_request_id: currentLoanRequestId,
            admin_notes: adminNotes
        };

        // Only include approved_amount if it's different from requested amount
        if (approvedAmount !== null && approvedAmount !== currentRequestedAmount) {
            requestBody.approved_amount = approvedAmount;
        }

        const response = await fetch(`${apiBaseUrl}/loan-approval.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include', // Include session cookies
            body: JSON.stringify(requestBody)
        });

        const result = await response.json();

        if (result.success) {
            Swal.fire('Success', result.message, 'success');
            closeApprovalModal();
            loadPendingApprovals();
        } else {
            Swal.fire('Error', result.message, 'error');
        }
    } catch (error) {
        console.error('Error approving loan:', error);
        Swal.fire('Error', 'Failed to approve loan', 'error');
    }
}

// Outstanding Loans Functions
let currentOutstandingLoanId = null;

async function loadOutstandingLoans() {
    try {
        const container = document.getElementById('outstanding-table-container');
        container.innerHTML =
            '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-blue-600 text-2xl mb-2"></i><p class="text-gray-500">Loading...</p></div>';

        const response = await fetch(`${apiBaseUrl}/outstanding-loans.php`, {
            credentials: 'include'
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();

        if (result.success) {
            displayOutstandingLoans(result.data || []);
        } else {
            container.innerHTML = loanHtml`<div class="text-center py-8 text-red-500">Error: ${result.message}</div>`;
        }
    } catch (error) {
        console.error('Error loading outstanding loans:', error);
        const container = document.getElementById('outstanding-table-container');
        container.innerHTML = loanHtml`<div class="text-center py-8 text-red-500">Failed to load: ${error.message}</div>`;
    }
}

function displayOutstandingLoans(loans) {
    const container = document.getElementById('outstanding-table-container');

    if (!loans || loans.length === 0) {
        container.innerHTML = '<div class="text-center py-8 text-gray-500">No outstanding loans</div>';
        return;
    }

    let html =
        '<div class="coop-loan-table overflow-x-auto" role="region" aria-label="Loan records. Scroll horizontally for all columns." tabindex="0"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requester</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requested</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Approved</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Outstanding</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Original Period</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Approved Date</th>';
    html += '<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>';
    html += '</tr></thead><tbody class="bg-white divide-y divide-gray-200">';

    loans.forEach((loan, index) => {
        const approvedDate = loan.approved_at ?
            new Date(loan.approved_at).toLocaleDateString('en-NG', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }) :
            'N/A';


        html += loanHtml`<tr>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${loan.requester_name || 'Unknown'}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">₦${parseFloat(loan.requested_amount).toLocaleString('en-NG', {minimumFractionDigits: 2})}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">₦${parseFloat(loan.approved_amount).toLocaleString('en-NG', {minimumFractionDigits: 2})}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-yellow-600">₦${parseFloat(loan.outstanding_amount).toLocaleString('en-NG', {minimumFractionDigits: 2})}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${loan.original_period_name || 'Period ' + loan.original_period_id}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${approvedDate}</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm">
                <button type="button" data-review-outstanding data-row-index="${index}"
                    class="px-3 py-1 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                    <i class="fas fa-download mr-1"></i> Import to Current Period
                </button>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
    bindLoanRows(container, '[data-review-outstanding]', loans, loan => openImportModal(loan.id, loan.requester_name, loan.outstanding_amount, loan.original_period_name || 'Period ' + loan.original_period_id));
}

function openImportModal(loanId, requesterName, outstandingAmount, originalPeriod) {
    currentOutstandingLoanId = loanId;

    const details = document.getElementById('import-details');
    details.innerHTML = loanHtml`
        <div class="space-y-2">
            <p><strong>Requester:</strong> ${requesterName}</p>
            <p><strong>Outstanding Amount:</strong> ₦${parseFloat(outstandingAmount).toLocaleString('en-NG', {minimumFractionDigits: 2})}</p>
            <p><strong>Original Period:</strong> ${originalPeriod}</p>
            <p class="text-sm text-gray-600 mt-2">Carrying this balance forward requires a new application and fresh guarantor consent. This review does not create or pay a loan.</p>
        </div>
    `;

    // Load periods for import
    loadPeriodsForImport();

    setLoanModalOpen('importModal', true);
}

function closeImportModal() {
    setLoanModalOpen('importModal', false);
    currentOutstandingLoanId = null;
}

async function loadPeriodsForImport() {
    const select = document.getElementById('import-period-id');
    select.innerHTML = '<option value="">Loading...</option>';

    try {
        const response = await fetch(`${apiBaseUrl}/periods.php`, {
            credentials: 'include'
        });
        const result = await response.json();

        if (result.success && result.data && result.data.length > 0) {
            select.innerHTML = '<option value="">Select Period</option>';
            result.data.forEach(period => {
                const option = document.createElement('option');
                const periodId = period.id || period.period_id;
                const displayName = period.display_name || period.description || period.PayrollPeriod ||
                    period.name || `Period ${periodId}`;

                option.value = periodId;
                option.textContent = displayName;
                select.appendChild(option);
            });
        } else {
            select.innerHTML = '<option value="">No periods available</option>';
        }
    } catch (error) {
        console.error('Error loading periods:', error);
        select.innerHTML = '<option value="">Error loading periods</option>';
    }
}

async function importOutstandingLoan() {
    Swal.fire('Fresh consent required', 'Create a draft manual application and obtain fresh guarantor consent. The original balance has not changed.', 'info');
}

function getAuthToken() {
    // Admin panel uses session-based auth, so we don't need a token
    // But API endpoints expect Authorization header, so return empty or session ID
    return '';
}

// Manual Loan Functions
async function loadPeriodsForManualLoan() {
    const select = document.getElementById('manual-period-id');
    select.innerHTML = '<option value="">Loading...</option>';

    try {
        const response = await fetch(`${apiBaseUrl}/periods.php`, {
            credentials: 'include'
        });
        const result = await response.json();

        if (result.success && result.data && result.data.length > 0) {
            select.innerHTML = '<option value="">Select Period</option>';
            result.data.forEach(period => {
                const option = document.createElement('option');
                const periodId = period.id || period.period_id;
                const displayName = period.display_name || period.description || period.PayrollPeriod ||
                    period.name || `Period ${periodId}`;

                option.value = periodId;
                option.textContent = displayName;
                select.appendChild(option);
            });
        } else {
            select.innerHTML = '<option value="">No periods available</option>';
        }
    } catch (error) {
        console.error('Error loading periods:', error);
        select.innerHTML = '<option value="">Error loading periods</option>';
    }
}

let memberSearchTimeout = null;

function handleMemberSearchInput(event) {
    const query = event.target.value.trim();

    // Clear previous timeout
    if (memberSearchTimeout) {
        clearTimeout(memberSearchTimeout);
    }

    // Hide results if query is too short
    if (query.length < 2) {
        document.getElementById('manual-member-results').classList.add('hidden');
        document.getElementById('manual-member-info').classList.add('hidden');
        return;
    }

    // Debounce search
    memberSearchTimeout = setTimeout(() => {
        searchMemberForManualLoan();
    }, 300);
}

async function searchMemberForManualLoan() {
    const coopId = document.getElementById('manual-member-coop-id').value.trim();
    const resultsDiv = document.getElementById('manual-member-results');
    const infoDiv = document.getElementById('manual-member-info');

    if (!coopId || coopId.length < 2) {
        resultsDiv.classList.add('hidden');
        return;
    }

    try {
        // Use the correct search endpoint (with underscore, not hyphen)
        const response = await fetch(`auth_api/api/auth/search_users.php?query=${encodeURIComponent(coopId)}`, {
            credentials: 'include'
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();

        if (result.success && result.data && result.data.length > 0) {
            // Display all results in a dropdown
            let html = '';
            result.data.forEach((member, index) => {
                const fullName =
                    `${member.FirstName || ''} ${member.MiddleName || ''} ${member.LastName || ''}`.trim();
                html += loanHtml`
                    <button type="button" data-select-member data-row-index="${index}"
                        class="px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 ${index === 0 ? 'border-t-0' : ''}">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <p class="font-semibold text-gray-900">${fullName}</p>
                                <p class="text-sm text-gray-600">CoopID: ${member.CoopID}</p>
                                ${trustedLoanHtml(member.EmailAddress && member.EmailAddress !== 'NONE' ? loanHtml`<p class="text-xs text-gray-500">${member.EmailAddress}</p>` : '')}
                            </div>
                            <i class="fas fa-chevron-right text-gray-400"></i>
                        </div>
                    </button>
                `;
            });

            resultsDiv.innerHTML = html;
            bindLoanRows(resultsDiv, '[data-select-member]', result.data, member => selectMemberForManualLoan(member.CoopID, [member.FirstName, member.MiddleName, member.LastName].filter(Boolean).join(' '), member.EmailAddress || '', member.MobileNumber || ''));
            resultsDiv.classList.remove('hidden');

            // If only one result and it's an exact CoopID match, auto-select it
            if (result.data.length === 1 && result.data[0].CoopID.toUpperCase() === coopId.toUpperCase()) {
                const member = result.data[0];
                const fullName = `${member.FirstName || ''} ${member.MiddleName || ''} ${member.LastName || ''}`
                    .trim();
                selectMemberForManualLoan(member.CoopID, fullName, member.EmailAddress || '', member.MobileNumber ||
                    '');
            }
        } else {
            resultsDiv.innerHTML = loanHtml`
                <div class="px-4 py-3 text-center text-gray-500">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    No members found
                </div>
            `;
            resultsDiv.classList.remove('hidden');
            infoDiv.classList.add('hidden');
        }
    } catch (error) {
        console.error('Error searching member:', error);
        resultsDiv.innerHTML = loanHtml`
            <div class="px-4 py-3 text-center text-red-500">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                Error searching: ${error.message}
            </div>
        `;
        resultsDiv.classList.remove('hidden');
    }
}

function selectMemberForManualLoan(coopId, fullName, email, mobile) {
    // Set the CoopID in the input
    document.getElementById('manual-member-coop-id').value = coopId;

    // Hide results dropdown
    document.getElementById('manual-member-results').classList.add('hidden');

    // Show selected member info
    const infoDiv = document.getElementById('manual-member-info');
    infoDiv.innerHTML = loanHtml`
        <div class="flex items-center justify-between">
            <div>
                <p class="font-semibold text-gray-900">${fullName}</p>
                <p class="text-sm text-gray-600">CoopID: ${coopId}</p>
                ${trustedLoanHtml(email && email !== 'NONE' ? loanHtml`<p class="text-sm text-gray-600">Email: ${email}</p>` : '')}
                ${trustedLoanHtml(mobile ? loanHtml`<p class="text-sm text-gray-600">Mobile: ${mobile}</p>` : '')}
            </div>
            <button type="button" data-clear-member aria-label="Clear selected member" class="text-gray-400 hover:text-red-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    infoDiv.querySelector('[data-clear-member]')?.addEventListener('click', clearSelectedMember);
    infoDiv.classList.remove('hidden');
}

function clearSelectedMember() {
    document.getElementById('manual-member-coop-id').value = '';
    document.getElementById('manual-member-info').classList.add('hidden');
    document.getElementById('manual-member-results').classList.add('hidden');
}

// Guarantor search functions
let guarantorSearchTimeouts = {};

function handleGuarantorSearchInput(event, guarantorNum) {
    const query = event.target.value.trim();

    // Clear previous timeout
    if (guarantorSearchTimeouts[guarantorNum]) {
        clearTimeout(guarantorSearchTimeouts[guarantorNum]);
    }

    // Hide results if query is too short
    if (query.length < 2) {
        document.getElementById(`manual-guarantor-${guarantorNum}-results`).classList.add('hidden');
        document.getElementById(`manual-guarantor-${guarantorNum}-info`).classList.add('hidden');
        return;
    }

    // Debounce search
    guarantorSearchTimeouts[guarantorNum] = setTimeout(() => {
        searchGuarantorForManualLoan(guarantorNum);
    }, 300);
}

async function searchGuarantorForManualLoan(guarantorNum) {
    const input = document.getElementById(`manual-guarantor-${guarantorNum}-coop-id`);
    const query = input.value.trim();
    const resultsDiv = document.getElementById(`manual-guarantor-${guarantorNum}-results`);
    const infoDiv = document.getElementById(`manual-guarantor-${guarantorNum}-info`);

    if (!query || query.length < 2) {
        resultsDiv.classList.add('hidden');
        return;
    }

    try {
        const response = await fetch(`auth_api/api/auth/search_users.php?query=${encodeURIComponent(query)}`, {
            credentials: 'include'
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();

        if (result.success && result.data && result.data.length > 0) {
            // Filter out the requester and other guarantor
            const requesterCoopId = document.getElementById('manual-member-coop-id').value.trim();
            const otherGuarantorNum = guarantorNum === 1 ? 2 : 1;
            const otherGuarantorCoopId = document.getElementById(`manual-guarantor-${otherGuarantorNum}-coop-id`)
                .value.trim();

            const filteredResults = result.data.filter(member => {
                return member.CoopID !== requesterCoopId && member.CoopID !== otherGuarantorCoopId;
            });

            if (filteredResults.length > 0) {
                let html = '';
                filteredResults.forEach((member, index) => {
                    const fullName =
                        `${member.FirstName || ''} ${member.MiddleName || ''} ${member.LastName || ''}`
                        .trim();
                    html += loanHtml`
                        <button type="button" data-select-guarantor data-row-index="${index}"
                            class="px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 ${index === 0 ? 'border-t-0' : ''}">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-semibold text-gray-900 text-sm">${fullName}</p>
                                    <p class="text-xs text-gray-600">CoopID: ${member.CoopID}</p>
                                </div>
                                <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
                            </div>
                        </button>
                    `;
                });

                resultsDiv.innerHTML = html;
                bindLoanRows(resultsDiv, '[data-select-guarantor]', filteredResults, member => selectGuarantorForManualLoan(guarantorNum, member.CoopID, [member.FirstName, member.MiddleName, member.LastName].filter(Boolean).join(' ')));
                resultsDiv.classList.remove('hidden');

                // Auto-select if exact match
                if (filteredResults.length === 1 && filteredResults[0].CoopID.toUpperCase() === query
                    .toUpperCase()) {
                    const member = filteredResults[0];
                    const fullName = `${member.FirstName || ''} ${member.MiddleName || ''} ${member.LastName || ''}`
                        .trim();
                    selectGuarantorForManualLoan(guarantorNum, member.CoopID, fullName);
                }
            } else {
                resultsDiv.innerHTML = loanHtml`
                    <div class="px-3 py-2 text-center text-gray-500 text-xs">
                        <i class="fas fa-exclamation-circle mr-1"></i>
                        No available guarantors found
                    </div>
                `;
                resultsDiv.classList.remove('hidden');
            }
        } else {
            resultsDiv.innerHTML = loanHtml`
                <div class="px-3 py-2 text-center text-gray-500 text-xs">
                    <i class="fas fa-exclamation-circle mr-1"></i>
                    No members found
                </div>
            `;
            resultsDiv.classList.remove('hidden');
        }
    } catch (error) {
        console.error('Error searching guarantor:', error);
        resultsDiv.innerHTML = loanHtml`
            <div class="px-3 py-2 text-center text-red-500 text-xs">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                Error: ${error.message}
            </div>
        `;
        resultsDiv.classList.remove('hidden');
    }
}

function selectGuarantorForManualLoan(guarantorNum, coopId, fullName) {
    // Set the CoopID in the input
    document.getElementById(`manual-guarantor-${guarantorNum}-coop-id`).value = coopId;

    // Hide results dropdown
    document.getElementById(`manual-guarantor-${guarantorNum}-results`).classList.add('hidden');

    // Show selected guarantor info
    const infoDiv = document.getElementById(`manual-guarantor-${guarantorNum}-info`);
    infoDiv.innerHTML = loanHtml`
        <div class="flex items-center justify-between">
            <div>
                <span class="font-semibold text-gray-900">${fullName}</span>
                <span class="text-gray-600 ml-2">(${coopId})</span>
            </div>
            <button type="button" data-clear-guarantor aria-label="Clear selected guarantor" class="text-gray-400 hover:text-red-600">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>
    `;
    infoDiv.querySelector('[data-clear-guarantor]')?.addEventListener('click', () => clearSelectedGuarantor(guarantorNum));
    infoDiv.classList.remove('hidden');
}

function clearSelectedGuarantor(guarantorNum) {
    document.getElementById(`manual-guarantor-${guarantorNum}-coop-id`).value = '';
    document.getElementById(`manual-guarantor-${guarantorNum}-info`).classList.add('hidden');
    document.getElementById(`manual-guarantor-${guarantorNum}-results`).classList.add('hidden');
}

async function submitManualLoan() {
    const coopId = document.getElementById('manual-member-coop-id').value.trim();
    const periodId = document.getElementById('manual-period-id').value;
    const requestedAmount = parseFloat(document.getElementById('manual-requested-amount').value);
    const status = document.getElementById('manual-status').value;
    const adminNotes = document.getElementById('manual-admin-notes').value.trim();

    // Get guarantors
    const guarantor1 = document.getElementById('manual-guarantor-1-coop-id').value.trim();
    const guarantor2 = document.getElementById('manual-guarantor-2-coop-id').value.trim();
    const guarantors = [];
    if (guarantor1) guarantors.push(guarantor1);
    if (guarantor2) guarantors.push(guarantor2);

    // Validation
    if (!coopId || !periodId || !requestedAmount || requestedAmount <= 0) {
        Swal.fire('Error', 'Please fill in all required fields', 'error');
        return;
    }

    // Drafts may be completed later; sending invitations needs two guarantors.
    if (status !== 'draft' && guarantors.length < 2) {
        Swal.fire('Error', 'Please select two guarantors', 'error');
        return;
    }

    // Validate guarantors are not the same as requester
    if (guarantors.includes(coopId)) {
        Swal.fire('Error', 'Guarantors cannot be the same as the requester', 'error');
        return;
    }

    // Validate guarantors are not duplicates
    if (guarantor1 && guarantor2 && guarantor1 === guarantor2) {
        Swal.fire('Error', 'Guarantors must be different', 'error');
        return;
    }

    try {
        const requestBody = {
            requester_coop_id: coopId,
            period_id: parseInt(periodId),
            requested_amount: requestedAmount,
            status: status,
            admin_notes: adminNotes || null,
            guarantors: guarantors
        };

        const response = await fetch(`${apiBaseUrl}/manual-loan.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(requestBody)
        });

        const result = await response.json();

        if (result.success) {
            Swal.fire('Success', result.message, 'success');
            resetManualLoanForm();
            // Reload relevant tabs
            if (status === 'approved') {
                loadOutstandingLoans();
            } else if (status === 'submitted') {
                loadPendingApprovals();
            }
        } else {
            Swal.fire('Error', result.message, 'error');
        }
    } catch (error) {
        console.error('Error creating manual loan:', error);
        Swal.fire('Error', 'Failed to create loan: ' + error.message, 'error');
    }
}

function resetManualLoanForm() {
    document.getElementById('manual-loan-form').reset();
    document.getElementById('manual-member-info').classList.add('hidden');
    document.getElementById('manual-member-results').classList.add('hidden');
    clearSelectedGuarantor(1);
    clearSelectedGuarantor(2);
    loadPeriodsForManualLoan();
}

// Load initial data
document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('[role="tablist"]').addEventListener('keydown', function(event) {
        const tabs = Array.from(this.querySelectorAll('[role="tab"]'));
        const index = tabs.indexOf(document.activeElement);
        if (index < 0 || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
        tabs[next].focus(); tabs[next].click();
    });
    document.addEventListener('keydown', function(event) {
        if (!activeLoanModal || document.querySelector('.swal2-container')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            if (activeLoanModal.id === 'approvalModal') closeApprovalModal();
            else if (activeLoanModal.id === 'limitModal') closeLimitModal();
            else closeImportModal();
        } else if (event.key === 'Tab') {
            const controls = Array.from(activeLoanModal.querySelectorAll('button, input:not([type="hidden"]), select, textarea, a[href]')).filter(el => !el.disabled && el.getClientRects().length);
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    });
    loadLimits();
    // Pre-load periods for faster modal opening
    loadPeriodsForLimit();

    // Handle form submission
    const manualLoanForm = document.getElementById('manual-loan-form');
    if (manualLoanForm) {
        manualLoanForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            await submitManualLoan();
        });
    }

    // Close search results when clicking outside
    document.addEventListener('click', function(event) {
        const memberInput = document.getElementById('manual-member-coop-id');
        const resultsDiv = document.getElementById('manual-member-results');

        if (memberInput && resultsDiv && !memberInput.contains(event.target) && !resultsDiv.contains(
                event.target)) {
            resultsDiv.classList.add('hidden');
        }

        // Close guarantor search results
        for (let i = 1; i <= 2; i++) {
            const guarantorInput = document.getElementById(`manual-guarantor-${i}-coop-id`);
            const guarantorResults = document.getElementById(`manual-guarantor-${i}-results`);

            if (guarantorInput && guarantorResults && !guarantorInput.contains(event.target) && !
                guarantorResults.contains(event.target)) {
                guarantorResults.classList.add('hidden');
            }
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>

