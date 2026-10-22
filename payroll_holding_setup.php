<?php
require_once __DIR__ . '/includes/session_helper.php';
initSession();
require_once __DIR__ . '/includes/admin_access_helper.php';
if (findAdminAccessError($_SESSION) !== null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Administrator access is required to configure payroll holding.';
    exit;
}
header('Cache-Control: no-store');
$accounts = [];
$current = null;
$loadError = null;
try {
    require_once __DIR__ . '/Connections/coop.php';
    $accounts = $coop->query('SELECT id, account_code, account_name FROM coop_accounts WHERE is_active = 1 AND is_control_account = 0 ORDER BY account_code')->fetchAll(PDO::FETCH_ASSOC);
    $current = $coop->query('SELECT c.holding_account_id, a.account_code, a.account_name, a.is_active, a.is_control_account
        FROM coop_payroll_configuration c LEFT JOIN coop_accounts a ON a.id = c.holding_account_id WHERE c.id = 1')->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $error) {
    error_log('Payroll holding setup page: ' . $error->getMessage());
    http_response_code(500);
    $loadError = 'Payroll holding setup could not be loaded. Please try again or ask your system administrator to check the setup migration.';
}
$currentAvailable = $current && $current['account_code'] !== null && $current['is_active'] && !$current['is_control_account'];
$selected = $currentAvailable ? (string) $current['holding_account_id'] : '';
$pageTitle = 'Payroll holding setup';
require_once __DIR__ . '/includes/header.php';
?>

<section class="payroll-setup" aria-labelledby="holding-heading">
    <div class="holding-card">
        <h1 id="holding-heading">Payroll holding setup</h1>
        <p>Select the existing ledger account that will hold unallocated payroll deductions for review. This setting does not create an account or release held funds.</p>
        <p>Current account: <output id="current-holding-account"><?php
            echo $currentAvailable ? htmlspecialchars($current['account_code'] . ' — ' . $current['account_name'], ENT_QUOTES, 'UTF-8') : 'Not configured with an available posting account';
        ?></output></p>
        <?php if ($loadError !== null): ?>
            <p role="alert"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php elseif (!$accounts): ?>
            <p role="alert">No active posting ledger accounts are available. Review the chart of accounts before configuring payroll holding.</p>
        <?php else: ?>
            <?php if ($current && !$currentAvailable): ?>
                <p role="alert">The configured account is unavailable for posting. Select an existing active posting account below.</p>
            <?php endif; ?>
            <form id="payroll-holding-form" method="post" action="api/configure_payroll_holding.php" aria-describedby="holding-help">
                <label for="holding-account">Payroll holding ledger account (required)</label>
                <p id="holding-help" class="holding-help">Only existing active posting accounts are listed. Choose the account explicitly; no account is selected automatically during initial setup.</p>
                <select id="holding-account" name="holding_account_id" required aria-describedby="holding-help holding-status">
                    <option value="" disabled<?= $selected === '' ? ' selected' : '' ?>>Select an existing ledger account</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= htmlspecialchars((string) $account['id'], ENT_QUOTES, 'UTF-8') ?>"<?= (string) $account['id'] === $selected ? ' selected' : '' ?>><?= htmlspecialchars($account['account_code'] . ' — ' . $account['account_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <button id="holding-save" type="submit">Save payroll holding account</button>
                <p id="holding-status" role="status" aria-live="polite" tabindex="-1"></p>
            </form>
        <?php endif; ?>
        <p><a href="coop_chart_of_accounts.php">Review the chart of accounts</a></p>
    </div>
</section>
<script>
const holdingForm = document.getElementById('payroll-holding-form');
if (holdingForm) {
    const holdingSelect = document.getElementById('holding-account');
    const holdingSave = document.getElementById('holding-save');
    const holdingStatus = document.getElementById('holding-status');
    let holdingSaving = false;
    holdingForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (holdingSaving || !holdingForm.reportValidity()) return;
        holdingSaving = true;
        holdingSave.disabled = true;
        holdingForm.setAttribute('aria-busy', 'true');
        holdingStatus.setAttribute('role', 'status');
        holdingStatus.setAttribute('aria-live', 'polite');
        holdingStatus.textContent = 'Saving payroll holding account…';
        let holdingTimeout;
        try {
            const controller = new AbortController();
            holdingTimeout = setTimeout(() => controller.abort(), 30000);
            const response = await fetch(holdingForm.action, {method: 'POST', body: new FormData(holdingForm), credentials: 'same-origin', signal: controller.signal});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error || 'The server could not save payroll holding setup.');
            if (String(result.holding_account_id) !== holdingSelect.value) throw new Error('The saved account could not be verified. Reload setup to check the current account.');
            document.getElementById('current-holding-account').textContent = holdingSelect.selectedOptions[0].textContent;
            holdingStatus.textContent = 'Payroll holding account saved.';
        } catch (error) {
            holdingStatus.setAttribute('role', 'alert');
            holdingStatus.setAttribute('aria-live', 'assertive');
            holdingStatus.textContent = error && error.name === 'AbortError'
                ? 'The request took too long to confirm. Reload setup to check whether the account was saved before trying again.'
                : 'Unable to confirm the change. ' + (error instanceof Error && !['TypeError', 'SyntaxError'].includes(error.name) ? error.message : 'Reload setup to check the current account before trying again.');
        } finally {
            clearTimeout(holdingTimeout);
            holdingSaving = false;
            holdingSave.disabled = false;
            holdingForm.removeAttribute('aria-busy');
            holdingStatus.focus();
        }
    });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>


