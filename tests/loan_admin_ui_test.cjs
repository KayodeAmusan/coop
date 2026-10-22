const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../loan-request-admin.php', 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
const elements = new Map([...source.matchAll(/id="([^"]+)"/g)].map(match => [match[1], element(match[1])]));
let focused = element('return-target');
const header = element('header');
const main = element('main-content');
const sidebar = element('sidebar');
elements.set('main-content', main); elements.set('sidebar', sidebar);
const focusControl = element('modal-control');
function element(id) {
    const classes = new Set(); const attrs = new Map(); const listeners = new Map();
    return { id, value: '', textContent: '', innerHTML: '', reset() {}, contains(other) { return this === other; },
        focus() { focused = this; }, addEventListener(event, fn) { listeners.set(event, fn); }, click() { listeners.get('click')?.(); }, querySelector() { return focusControl; }, querySelectorAll() { return []; },
        setAttribute(k,v) { attrs.set(k,v); }, removeAttribute(k) { attrs.delete(k); },
        toggleAttribute(k,on) { if(on) attrs.set(k,''); else attrs.delete(k); }, getAttribute(k) { return attrs.get(k); },
        classList: { add(...names) { names.forEach(n => classes.add(n)); }, remove(...names) { names.forEach(n => classes.delete(n)); }, contains(n) { return classes.has(n); } }
    };
}
const document = { getElementById: id => elements.get(id) || null, addEventListener() {},
    querySelector: () => header, querySelectorAll: selector => selector === '.tab-button' ? ['limits','approvals','outstanding','manual'].map(t => elements.get('tab-'+t)) : [],
    body: element('body'), get activeElement() { return focused; } };
const context = vm.createContext({ document, window: {innerWidth:390},
    fetch: async () => ({ json: async () => ({ success: true, data: [] }) }),
    console, sessionStorage: { getItem: () => null },
    Swal: { fire() { throw Error('Unexpected error dialog'); } }
});
new vm.Script(script).runInContext(context);
const returnTarget = focused;
vm.runInContext("openApprovalModal(7, 'Synthetic Member', 10000, 2, 2)", context);
assert.equal(elements.get('approved-amount').value, 10000);
assert.equal(focused, focusControl, 'Opening a review must move focus into the dialog');
assert.equal(header.getAttribute('inert'), '', 'Background header must be inert during review');
assert(document.body.classList.contains('overflow-hidden'));
vm.runInContext('closeApprovalModal(); resetManualLoanForm();', context);
assert.equal(focused, returnTarget, 'Closing must return focus to the triggering control');
assert.equal(header.getAttribute('inert'), undefined);
assert(!document.body.classList.contains('overflow-hidden'));
assert.equal(sidebar.getAttribute('inert'), '', 'Closed mobile navigation remains inert');
vm.runInContext("showTab('manual')", context);
assert.equal(elements.get('tab-manual').getAttribute('aria-selected'), 'true');
assert.equal(elements.get('tab-limits').getAttribute('aria-selected'), 'false');
assert.equal(elements.get('tab-manual').tabIndex, 0);
assert.equal(elements.get('tab-limits').tabIndex, -1);
vm.runInContext("displayPendingApprovals([{id:7,requester_name:'Synthetic Member',requested_amount:10000,period_name:'January',status:'submitted',approved_guarantors:2,total_guarantors:2,guarantors:[]}])", context);
assert(elements.get('approvals-table-container').innerHTML.includes('Synthetic Member'));
elements.get('loan-request-search').value = 'missing member';
vm.runInContext('filterPendingApprovals()', context);
assert(elements.get('approvals-table-container').innerHTML.includes('No applications match'));
elements.get('loan-request-search').value = 'january';
vm.runInContext('filterPendingApprovals()', context);
assert(elements.get('approvals-table-container').innerHTML.includes('Synthetic Member'));
elements.get('loan-request-status').value = 'fully_guaranteed';
vm.runInContext('filterPendingApprovals()', context);
assert(elements.get('approvals-table-container').innerHTML.includes('No applications match'));
vm.runInContext("renderPendingApprovals([{id:7,requester_name:'Proof-required member',requested_amount:10000,status:'submitted',approved_guarantors:2,total_guarantors:2,can_approve:false,approval_block_reason:'<img src=x onerror=bad()> proof missing',guarantors:[{name:'Guarantor',coop_id:'G',status:'approved',signed_approval_status:'pending'}]}])",context);
let evidenceQueue=elements.get('approvals-table-container').innerHTML;
assert(evidenceQueue.includes('disabled aria-disabled="true"'),'Approval must use server readiness, not accepted direct-click counts');
assert(evidenceQueue.includes('Proof awaiting staff review'));assert(evidenceQueue.includes('Online response history: approved'));assert(evidenceQueue.includes('guarantor_evidence_review.php?loan_request_id=7'));
assert(!evidenceQueue.includes('<img'));assert(evidenceQueue.includes('&lt;img'));
vm.runInContext("renderPendingApprovals([{id:7,requester_name:'Reviewed member',requested_amount:10000,status:'fully_guaranteed',approved_guarantors:2,total_guarantors:2,can_approve:true,approval_block_reason:null,guarantors:[{name:'Guarantor',coop_id:'G',status:'approved',signed_approval_status:'accepted'}]}])",context);
evidenceQueue=elements.get('approvals-table-container').innerHTML;assert(!evidenceQueue.includes('disabled aria-disabled="true"'));assert(evidenceQueue.includes('Signed approval accepted'));assert(evidenceQueue.includes('Accepted signed proofs'));
const malicious = '</td><img src=x onerror="globalThis.attackTriggered=true">\"\'&';
context.malicious = malicious;
vm.runInContext("openApprovalModal(7, malicious, 10000, 2, 2)", context);
assert(!elements.get('approval-details').innerHTML.includes('<img'));
assert(elements.get('approval-details').innerHTML.includes('&lt;img'));
vm.runInContext("closeApprovalModal(); selectMemberForManualLoan(malicious, malicious, malicious, malicious); selectGuarantorForManualLoan(1, malicious, malicious)", context);
for(const id of ['manual-member-info','manual-guarantor-1-info']) {
    assert(!elements.get(id).innerHTML.includes('<img'));
    assert(elements.get(id).innerHTML.includes('&lt;img'));
}
vm.runInContext("displayOutstandingLoans([{id:1, requester_name:malicious, requested_amount:10, approved_amount:5, outstanding_amount:5, original_period_name:malicious}]);", context);
assert(!elements.get('outstanding-table-container').innerHTML.includes('<img'));
assert(!elements.get('outstanding-table-container').innerHTML.includes('onclick='));
const editButton=element('edit-limit');editButton.setAttribute('data-row-index','0');
elements.get('limits-table-container').querySelectorAll=()=>[editButton];
vm.runInContext("displayLimits([{id:7, period_id:1, period_name:malicious, limit_amount:10000, set_by:malicious, notes:malicious}])", context);
assert(!elements.get('limits-table-container').innerHTML.includes('<img'));
assert(!elements.get('limits-table-container').innerHTML.includes('onclick='));
editButton.click();assert.equal(elements.get('limit-notes').value,malicious,'Closure-bound notes must retain literal data');
vm.runInContext('closeLimitModal()',context);
assert.equal(context.attackTriggered,undefined);
assert(!/onclick=[^\n]*\$\{/.test(script),'Dynamic API strings must never enter inline handlers');
assert(!source.includes('id="skip-guarantor"'));
assert(!source.includes('id="manual-skip-guarantors"'));
assert(!source.includes('<option value="approved">'));
assert.match(source, /id="approved-amount"[^>]*readonly/);
for(const id of ['limitModal','approvalModal','importModal']) assert.match(source, new RegExp('id="'+id+'" role="dialog" aria-modal="true" aria-labelledby="'));
console.log('PASS admin approval review focus/restore, background isolation, tab states, local search/status filtering, manual form reset mandatory consent controls and malicious API text escaping');
console.log('DOM smoke check only; browser layout and network behavior remain unverified.');

