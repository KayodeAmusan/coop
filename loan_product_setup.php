<?php
require_once __DIR__ . '/includes/session_helper.php';
initSession();
require_once __DIR__ . '/includes/admin_access_helper.php';
if (findAdminAccessError($_SESSION) !== null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Administrator access is required to configure loan product drafts.';
    exit;
}
header('Cache-Control: no-store');
$pageTitle = 'Loan product drafts and calculation preview';
require_once __DIR__ . '/includes/header.php';
?>

<section id="loan-product-setup" class="loan-setup" aria-labelledby="draft-heading">
    <h1 id="draft-heading">Loan product drafts and calculation preview</h1>
    <noscript><p role="alert">JavaScript is required to load drafts and request server calculations. This page cannot save candidate rules without it.</p></noscript>
    <div class="draft-boundary" role="note">
        <strong>Drafts only. New loan applications remain disabled.</strong>
        <p>These candidate rules and numbered calculations do not publish a product, approve a loan, disburse funds or authenticate a manual review. Borrower eligibility, guarantor rules, fees, calendar rules and acceptance still require their completed product workflow.</p>
    </div>
    <section class="draft-card" aria-labelledby="create-heading">
        <h2 id="create-heading">Create a product draft</h2>
        <form id="draft-create-form" aria-describedby="submission-help">
            <div class="draft-grid">
                <div><label for="draft-code">Product code (required)</label><input id="draft-code" name="code" required maxlength="40" pattern="[A-Za-z0-9_-]{1,40}" aria-describedby="code-help"><p id="code-help" class="draft-help">Use letters, digits, underscores or hyphens.</p></div>
                <div><label for="draft-name">Product name (required)</label><input id="draft-name" name="name" required maxlength="150"></div>
            </div>
            <label for="draft-description">Candidate description</label><textarea id="draft-description" name="description"></textarea>
            <div class="draft-actions"><button type="submit">Create draft</button><button id="draft-new-submission" class="secondary" type="button">Start another draft submission</button></div>
            <p id="submission-help" class="draft-help">If a save is unconfirmed, retry unchanged details. Check the saved drafts before starting another submission.</p>
            <p id="draft-create-status" class="draft-status" role="status" aria-live="polite" tabindex="-1"></p>
        </form>
    </section>
    <section class="draft-card" aria-labelledby="choose-heading">
        <h2 id="choose-heading">Choose a draft and review saved versions</h2>
        <label for="draft-product">Product draft (required to save a version)</label>
        <select id="draft-product" aria-describedby="product-help"><option value="" selected>Choose a product draft</option></select>
        <p id="product-help" class="draft-help">A saved version is an immutable candidate. Saving another version does not replace earlier versions.</p>
        <div class="draft-actions"><button id="products-previous" class="secondary" type="button" disabled>Previous products</button><button id="products-next" class="secondary" type="button" disabled>Next products</button><button id="products-reload" class="secondary" type="button">Reload drafts</button></div>
        <p id="products-page" class="draft-help"></p><p id="draft-products-status" class="draft-status" role="status" aria-live="polite" tabindex="-1"></p>
        <ol id="draft-versions" aria-label="Saved draft versions"></ol>
        <div class="draft-actions"><button id="versions-previous" class="secondary" type="button" disabled>Previous versions</button><button id="versions-next" class="secondary" type="button" disabled>Next versions</button></div>
        <p id="versions-page" class="draft-help"></p><p id="draft-versions-status" class="draft-status" role="status" aria-live="polite" tabindex="-1">Choose a draft to review its versions.</p>
    </section>
    <section class="draft-card" aria-labelledby="calculate-heading">
        <h2 id="calculate-heading">Candidate calculation</h2>
        <p>No financial inputs are preselected. Money and fractional rates are sent as the decimal strings you enter; the server performs every calculation.</p>
        <form id="draft-calculation-form" aria-describedby="calculation-help">
            <fieldset><legend>Explicit calculation inputs</legend>
                <div class="draft-grid">
                    <div><label for="calculation-method">Calculation method (required)</label><select id="calculation-method" required>
                        <option value="" selected>Choose a method</option><option value="interest_free">Interest-free</option><option value="flat">Flat interest on original principal</option><option value="reducing_equal_installments">Reducing balance — equal installments</option><option value="reducing_equal_principal">Reducing balance — equal principal</option><option value="interest_only_balloon">Interest-only — final principal balloon</option><option value="manual_schedule">Candidate manual schedule</option>
                    </select></div>
                    <div><label for="calculation-principal">Principal (₦, required)</label><input id="calculation-principal" required inputmode="decimal" pattern="[0-9]{1,13}(\.[0-9]{1,2})?" aria-describedby="money-help"></div>
                    <div><label for="calculation-count">Number of installments (required)</label><input id="calculation-count" required inputmode="numeric" pattern="[1-9][0-9]{0,3}" aria-describedby="count-help"><p id="count-help" class="draft-help">The calculator has a technical limit of 1–1200 installments. This is not an approved duration policy.</p></div>
                    <div><label for="calculation-rounding">Kobo rounding mode (required)</label><select id="calculation-rounding" required><option value="" selected>Choose rounding</option><option value="half_up">Half up — ties round up</option><option value="half_even">Half even — ties round to an even kobo</option><option value="down">Down — toward zero</option><option value="up">Up — away from zero</option></select></div>
                    <div><label for="calculation-residual">Final residual treatment (required)</label><select id="calculation-residual" required><option value="" selected>Choose residual treatment</option><option value="adjust_final_installment">Adjust the final installment to reconcile exactly</option></select></div>
                    <div><label for="calculation-fees">Fee handling (required)</label><select id="calculation-fees" required aria-describedby="fees-help"><option value="" selected>Choose fee handling</option><option value="none">Explicitly fee-free — no fees</option><option value="unsupported">Fees required — not supported by this calculator</option></select><p id="fees-help" class="draft-help">Paid, financed or deducted fees are not implemented. Selecting fees blocks preview and version saving; they are never silently treated as zero.</p></div>
                </div>
                <p id="money-help" class="draft-help">Enter plain decimal amounts with at most two decimal places, without commas or a currency symbol.</p>
                <fieldset id="calculation-rate-fields" hidden disabled><legend>Explicit rate for this method</legend><div class="draft-grid">
                    <div><label for="calculation-rate-basis">Rate basis (required)</label><select id="calculation-rate-basis" required aria-describedby="rate-help"><option value="" selected>Choose rate basis</option><option value="per_installment">Per numbered installment</option><option value="unsupported">Monthly, annual or day-count conversion — unsupported</option></select></div>
                    <div><label for="calculation-rate">Fractional rate per installment (required)</label><input id="calculation-rate" required inputmode="decimal" pattern="(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,18})?" aria-describedby="rate-help"></div>
                </div><p id="rate-help" class="draft-help">A rate of 1% is entered as the fraction 0.01, not 1. Rates apply per numbered installment; no monthly or annual conversion is assumed. Enter 0 explicitly if the selected method uses a zero rate.</p></fieldset>
                <fieldset id="calculation-manual-fields" hidden disabled><legend>Candidate manual allocations</legend>
                    <label for="calculation-manual-interest">Candidate total interest (₦, required)</label><input id="calculation-manual-interest" required inputmode="decimal" pattern="[0-9]{1,13}(\.[0-9]{1,2})?" aria-describedby="manual-help">
                    <label for="calculation-manual-schedule">Manual principal, interest rows (required)</label><textarea id="calculation-manual-schedule" required rows="6" spellcheck="false" aria-describedby="manual-help"></textarea>
                    <p id="manual-help" class="draft-help">Enter one installment per line as principal,interest using decimal amounts. The number of rows must equal the installment count. The server checks principal and interest totals; this entry does not prove an authenticated staff review.</p>
                </fieldset>
            </fieldset>
            <p id="calculation-help" class="draft-help">Results contain numbered installments only, without actual due dates, grace periods or calendar assumptions.</p>
            <label for="draft-terms">Candidate terms (required to save a version)</label><textarea id="draft-terms" aria-describedby="terms-help"></textarea><p id="terms-help" class="draft-help">Draft text is stored with the candidate calculation inputs. It is not borrower acceptance or approved policy.</p>
            <div class="draft-actions"><button type="submit">Preview server calculation</button><button id="draft-save-version" type="button">Save new draft version</button><button id="version-new-submission" class="secondary" type="button">Start another version submission</button></div>
            <p id="draft-calculation-status" class="draft-status" role="status" aria-live="polite" tabindex="-1"></p>
        </form>
        <section id="calculation-result" hidden aria-labelledby="result-heading">
            <h2 id="result-heading">Server calculation preview</h2><p id="calculation-totals"></p><p class="draft-help">Calculation only. This is not a published offer, an authenticated manual review or a dated repayment schedule.</p>
            <details><summary>Inputs used for this preview</summary><pre id="calculation-snapshot" tabindex="0"></pre></details>
            <div class="draft-table-region" tabindex="0" role="region" aria-label="Numbered calculation installments"><table><caption>Numbered installments — no due dates</caption><thead><tr><th scope="col">Number</th><th scope="col">Opening principal (₦)</th><th scope="col">Principal (₦)</th><th scope="col">Interest (₦)</th><th scope="col">Repayment (₦)</th><th scope="col">Closing principal (₦)</th></tr></thead><tbody id="calculation-installments"></tbody></table></div>
        </section>
    </section>
</section>
<script>
const draftRoot = document.getElementById('loan-product-setup');
const draftById = id => document.getElementById(id);
const draftApi = 'api/loan_product_drafts.php';
const draftCommands = {create: null, version: null};
let draftBusy = false, draftProductPage = 1, draftVersionPage = 1;
let draftProductTotal = 0, draftVersionTotal = 0;
const draftPageSize = 20;

function draftStatus(id, message, error = false) {
    const element = draftById(id);
    element.setAttribute('role', error ? 'alert' : 'status');
    element.setAttribute('aria-live', error ? 'assertive' : 'polite');
    element.textContent = message;
}

async function draftRequest(payload, query = null) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
        const response = await fetch(query ? draftApi + '?' + new URLSearchParams(query) : draftApi,
            query ? {credentials: 'same-origin', signal: controller.signal} : {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload), signal: controller.signal});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || result.error || 'The server could not complete this request.');
        if (result.draft_only !== true || result.applications_enabled !== false) throw new Error('The draft-only setup boundary could not be verified.');
        return result.data;
    } catch (error) {
        if (error && error.name === 'AbortError') throw new Error('The request took too long to confirm. Reload drafts and versions to check whether a save completed; retry unchanged details only.');
        if (!error || ['TypeError', 'SyntaxError'].includes(error.name)) throw new Error('The result could not be confirmed. Reload drafts and versions before retrying an uncertain save.');
        throw error;
    } finally { clearTimeout(timeout); }
}

function draftCommandPayload(kind, payload) {
    const signature = JSON.stringify(payload);
    if (draftCommands[kind] && draftCommands[kind].signature !== signature) throw new Error('An earlier submission is unconfirmed. Retry its unchanged details, or check saved records before starting another submission.');
    if (!draftCommands[kind]) {
        if (!globalThis.crypto || !globalThis.crypto.getRandomValues) throw new Error('A secure submission reference is unavailable. Reload this page before saving.');
        const bytes = new Uint8Array(24); crypto.getRandomValues(bytes);
        draftCommands[kind] = {signature, key: Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')};
    }
    return {...payload, idempotency_key: draftCommands[kind].key};
}

async function draftRun(statusId, operation) {
    if (draftBusy) return;
    draftBusy = true;
    const controls = Array.from(draftRoot.querySelectorAll('input,select,textarea,button,fieldset')).map(control => [control, control.disabled]);
    controls.forEach(([control]) => {control.disabled = true;});
    draftRoot.setAttribute('aria-busy', 'true');
    draftStatus(statusId, 'Working…');
    try { await operation(); }
    catch (error) { draftStatus(statusId, error instanceof Error ? error.message : 'The request could not be confirmed. Reload saved records before retrying.', true); }
    finally {
        controls.forEach(([control, disabled]) => {control.disabled = disabled;});
        draftBusy = false;
        draftRoot.removeAttribute('aria-busy');
        draftUpdatePaging();
        draftById(statusId).focus();
    }
}

function draftUpdatePaging() {
    draftById('products-previous').disabled = draftProductPage <= 1;
    draftById('products-next').disabled = draftProductPage * draftPageSize >= draftProductTotal;
    draftById('versions-previous').disabled = !draftById('draft-product').value || draftVersionPage <= 1;
    draftById('versions-next').disabled = !draftById('draft-product').value || draftVersionPage * draftPageSize >= draftVersionTotal;
}

async function draftLoadProducts(selectedId = draftById('draft-product').value) {
    const data = await draftRequest(null, {action: 'list', page: draftProductPage, limit: draftPageSize});
    const select = draftById('draft-product'); select.replaceChildren();
    const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = 'Choose a product draft'; select.appendChild(placeholder);
    for (const item of data.items) {
        const option = document.createElement('option'); option.value = String(item.id); option.textContent = item.product_code + ' — ' + item.name + ' (' + item.status + ')'; select.appendChild(option);
    }
    select.value = data.items.some(item => String(item.id) === String(selectedId)) ? String(selectedId) : '';
    draftProductTotal = data.total;
    draftById('products-page').textContent = 'Product page ' + draftProductPage + ' · ' + data.total + ' draft records';
    draftStatus('draft-products-status', data.items.length ? 'Draft list loaded. Applications remain disabled.' : 'No product drafts have been saved.');
}

async function draftLoadVersions() {
    const product = draftById('draft-product').value;
    const list = draftById('draft-versions'); list.replaceChildren(); draftVersionTotal = 0;
    if (!product) { draftById('versions-page').textContent = ''; draftStatus('draft-versions-status', 'Choose a draft to review its versions.'); return; }
    const data = await draftRequest(null, {action: 'versions', product_id: product, page: draftVersionPage, limit: draftPageSize});
    for (const item of data.items) {
        const entry = document.createElement('li');
        const heading = document.createElement('p'); heading.textContent = 'Draft version ' + item.version_number + ' · ' + item.created_at; entry.appendChild(heading);
        const hash = document.createElement('p'); hash.textContent = 'Content hash: ' + item.content_hash; hash.className = 'draft-help'; entry.appendChild(hash);
        const details = document.createElement('details'); const summary = document.createElement('summary'); summary.textContent = 'Candidate terms and calculation inputs'; details.appendChild(summary);
        const terms = document.createElement('p'); terms.textContent = item.terms_text || 'No candidate terms stored.'; details.appendChild(terms);
        const inputs = document.createElement('pre'); inputs.tabIndex = 0; inputs.textContent = JSON.stringify(item.configuration, null, 2); details.appendChild(inputs); entry.appendChild(details); list.appendChild(entry);
    }
    draftVersionTotal = data.total;
    draftById('versions-page').textContent = 'Version page ' + draftVersionPage + ' · ' + data.total + ' immutable candidate versions';
    draftStatus('draft-versions-status', data.items.length ? 'Saved draft versions loaded. None enable applications.' : 'No versions have been saved for this draft.');
}

function draftMethodFields() {
    const method = draftById('calculation-method').value;
    const rated = ['flat', 'reducing_equal_installments', 'reducing_equal_principal', 'interest_only_balloon'].includes(method);
    const manual = method === 'manual_schedule';
    draftById('calculation-rate-fields').hidden = !rated; draftById('calculation-rate-fields').disabled = !rated;
    draftById('calculation-manual-fields').hidden = !manual; draftById('calculation-manual-fields').disabled = !manual;
}

function draftCalculationInputs() {
    const method = draftById('calculation-method').value;
    const count = draftById('calculation-count').value.trim();
    if (!/^[1-9][0-9]*$/.test(count) || Number(count) > 1200) throw new Error('Enter an explicit installment count from 1 to 1200 (technical limit).');
    if (draftById('calculation-fees').value !== 'none') throw new Error('Configured fees are unsupported. This calculator cannot preview or save them as zero fees.');
    const input = {principal: draftById('calculation-principal').value.trim(), method, installment_count: Number(count),
        rounding_mode: draftById('calculation-rounding').value, residual_treatment: draftById('calculation-residual').value, fees: {handling: 'none'}};
    if (['flat', 'reducing_equal_installments', 'reducing_equal_principal', 'interest_only_balloon'].includes(method)) {
        if (draftById('calculation-rate-basis').value !== 'per_installment') throw new Error('Only explicitly per-installment fractional rates are supported; no rate conversion is assumed.');
        input.rate_basis = 'per_installment'; input.rate_per_installment = draftById('calculation-rate').value.trim();
    }
    if (method === 'manual_schedule') {
        input.reviewed_total_interest = draftById('calculation-manual-interest').value.trim();
        input.schedule = draftById('calculation-manual-schedule').value.trim().split(/\r?\n/).filter(line => line.trim() !== '').map(line => {
            const pair = line.split(',').map(value => value.trim());
            if (pair.length !== 2 || pair.some(value => value === '')) throw new Error('Each manual row must contain exactly principal,interest as two decimal amounts.');
            return {principal: pair[0], interest: pair[1]};
        });
    }
    return input;
}

function draftRenderCalculation(data, inputs) {
    draftById('calculation-totals').textContent = 'Principal ₦' + data.principal + ' · Interest ₦' + data.total_interest + ' · Total repayable ₦' + data.total_repayable + ' · ' + data.installment_count + ' numbered installments';
    draftById('calculation-snapshot').textContent = JSON.stringify(inputs, null, 2);
    const body = draftById('calculation-installments'); body.replaceChildren();
    for (const item of data.installments) {
        const row = document.createElement('tr');
        for (const key of ['number', 'opening_principal', 'principal', 'interest', 'repayment', 'closing_principal']) { const cell = document.createElement('td'); cell.textContent = String(item[key]); row.appendChild(cell); }
        body.appendChild(row);
    }
    draftById('calculation-result').hidden = false;
}

draftById('draft-create-form').addEventListener('submit', async event => {
    event.preventDefault(); if (draftBusy || !draftById('draft-create-form').reportValidity()) return;
    let payload;
    try { payload = draftCommandPayload('create', {action: 'create_draft', code: draftById('draft-code').value.trim(), name: draftById('draft-name').value.trim(), description: draftById('draft-description').value.trim()}); }
    catch (error) { draftStatus('draft-create-status', error.message, true); draftById('draft-create-status').focus(); return; }
    await draftRun('draft-create-status', async () => {
        const saved = await draftRequest(payload); draftCommands.create = null;
        draftStatus('draft-create-status', 'Product draft ' + saved.id + ' saved. Applications remain disabled.');
        draftProductPage = 1;
        try { await draftLoadProducts(saved.id); draftVersionPage = 1; await draftLoadVersions(); }
        catch (error) { draftStatus('draft-products-status', 'The draft was saved, but its list could not refresh. Use Reload drafts to check it.', true); }
    });
});

async function draftCalculate(saveVersion) {
    if (draftBusy || !draftById('draft-calculation-form').reportValidity()) return;
    let inputs, payload;
    try {
        inputs = draftCalculationInputs(); payload = {action: 'preview', calculation_inputs: inputs};
        if (saveVersion) {
            const product = draftById('draft-product').value;
            if (!/^[1-9][0-9]*$/.test(product)) throw new Error('Choose the product draft that will receive this new version.');
            const terms = draftById('draft-terms').value.trim(); if (!terms) throw new Error('Enter explicit candidate terms before saving a draft version.');
            payload = draftCommandPayload('version', {action: 'save_version', product_id: Number(product), calculation_inputs: inputs, terms});
        }
    } catch (error) { draftStatus('draft-calculation-status', error.message, true); draftById('draft-calculation-status').focus(); return; }
    await draftRun('draft-calculation-status', async () => {
        const data = await draftRequest(payload);
        if (saveVersion) {
            draftCommands.version = null; draftStatus('draft-calculation-status', 'Immutable draft version ' + data.version_number + ' saved. Applications remain disabled.'); draftVersionPage = 1;
            try { await draftLoadVersions(); } catch (error) { draftStatus('draft-versions-status', 'The version was saved, but history could not refresh. Reload drafts to check it.', true); }
        } else { draftRenderCalculation(data, inputs); draftStatus('draft-calculation-status', 'Server calculation completed. This preview does not enable applications.'); }
    });
}

draftById('draft-calculation-form').addEventListener('submit', event => {event.preventDefault(); draftCalculate(false);});
draftById('draft-save-version').addEventListener('click', () => draftCalculate(true));
draftById('calculation-method').addEventListener('change', draftMethodFields);
draftById('draft-product').addEventListener('change', () => {draftVersionPage = 1; draftRun('draft-versions-status', draftLoadVersions);});
draftById('draft-new-submission').addEventListener('click', () => {draftCommands.create = null; draftStatus('draft-create-status', 'Another submission can now start. Check saved drafts before repeating an uncertain save.');});
draftById('version-new-submission').addEventListener('click', () => {draftCommands.version = null; draftStatus('draft-calculation-status', 'Another version submission can now start. Check saved versions before repeating an uncertain save.');});
for (const [id, delta] of [['products-previous', -1], ['products-next', 1]]) draftById(id).addEventListener('click', () => draftRun('draft-products-status', async () => {
    const previous = draftProductPage; draftProductPage += delta;
    try { await draftLoadProducts(''); draftVersionPage = 1; await draftLoadVersions(); }
    catch (error) { draftProductPage = previous; throw error; }
}));
for (const [id, delta] of [['versions-previous', -1], ['versions-next', 1]]) draftById(id).addEventListener('click', () => draftRun('draft-versions-status', async () => {
    const previous = draftVersionPage; draftVersionPage += delta;
    try { await draftLoadVersions(); } catch (error) { draftVersionPage = previous; throw error; }
}));
draftById('products-reload').addEventListener('click', () => draftRun('draft-products-status', async () => {await draftLoadProducts(); await draftLoadVersions();}));
draftMethodFields();
draftRun('draft-products-status', draftLoadProducts);
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

