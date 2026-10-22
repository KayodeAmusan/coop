<?php
/** Render real dashboard branches with harmless SQL/settings spies; no database connection. */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/admin_access_helper.php';
function retrieveSettings($table, $column, $id, $key) { $GLOBALS['dashboardSettingsCalls']++; return '0'; }
function renderDashboardForRole($role) {
    $source = file_get_contents(__DIR__ . '/../home.php');
    $source = str_replace(["require_once('Connections/coop.php');", "include_once('classes/model.php');", "include 'includes/header.php';", "include 'includes/footer.php';"], '', $source);
    $_SESSION = ['SESS_MEMBER_ID' => 'fixture-user', 'role' => $role];
    $canAdministerCooperative = findAdminAccessError($_SESSION) === null;
    $userRole = $role;
    $coop = new class {
        public $queries = 0;
        public function query($sql) { $this->queries++; return new class { public function rowCount() { return 0; } }; }
    };
    $GLOBALS['dashboardSettingsCalls'] = 0;
    ob_start();
    try { eval('?>' . $source); $html = ob_get_contents(); } finally { ob_end_clean(); }
    return [$html, $coop->queries, $GLOBALS['dashboardSettingsCalls']];
}
foreach (['Member', 'user', 'Accountant', 'Finance'] as $role) {
    test("Dashboard does not query or expose cooperative financial data for $role", function () use ($role) {
        [$html, $queries, $settings] = renderDashboardForRole($role);
        assertSameValue(0, $queries); assertSameValue(0, $settings);
        foreach (['Cash & Bank', 'Accounting balances unavailable', 'Cooperative settings', 'href="coop_general_ledger.php"'] as $private) {
            assertSameValue(false, strpos($html, $private));
        }
    });
}
foreach (['Admin', 'administrator', 'superadmin'] as $role) {
    test("Dashboard uses known admin access rule and explicit unavailable balance state for $role", function () use ($role) {
        [$html, $queries, $settings] = renderDashboardForRole($role);
        assertSameValue(1, $queries); assertSameValue(6, $settings);
        assertContains('Accounting balances unavailable', $html);
        assertContains('href="coop_general_ledger.php"', $html);
    });
}
finishTests();
