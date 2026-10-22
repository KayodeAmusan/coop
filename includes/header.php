<?php
// Include session helper
require_once(__DIR__ . '/session_helper.php');

// Start session if not already started
initSession();

// Check authentication
if (!isset($_SESSION['SESS_MEMBER_ID']) || (trim($_SESSION['SESS_MEMBER_ID']) == '')) {
    header("location: index.php");
    exit();
}

require_once(__DIR__ . '/admin_access_helper.php');
$canAdministerCooperative = findAdminAccessError($_SESSION) === null;

// Get user info
$userName = $_SESSION['SESS_FIRST_NAME'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'User';
$currentPage = $_SERVER["PHP_SELF"];
require_once __DIR__.'/branding_view_helper.php';
$cooperativeBrand = cooperativeBrandingView(isset($coop) && $coop instanceof PDO ? $coop : null);
$pageTitle = str_replace('OOUTH COOP', $cooperativeBrand['name'], $pageTitle ?? $cooperativeBrand['name']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>

    <link rel="apple-touch-icon" sizes="180x180" href="images/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16x16.png">
    <link rel="manifest" href="../site.webmanifest">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- jQuery UI for autocomplete -->
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/ui-lightness/jquery-ui.css">
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>

    <style>
    .fade-in {
        animation: fadeIn 0.5s ease-in;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .gradient-bg {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }

    .card-hover {
        transition: all 0.3s ease;
    }

    .card-hover:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }

    .loading-spinner {
        border: 3px solid #f3f3f3;
        border-top: 3px solid #3498db;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    /* Custom scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
    }

    ::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }

    ::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 4px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* Autocomplete dropdown styling */
    .ui-autocomplete {
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        background: white;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .ui-menu-item {
        padding: 8px 12px;
        border-bottom: 1px solid #f3f4f6;
        cursor: pointer;
    }

    .ui-menu-item:hover {
        background-color: #f3f4f6;
    }

    .ui-menu-item:last-child {
        border-bottom: none;
    }

    /* Sidebar styles */
    .sidebar {
        transition: transform 0.3s ease-in-out;
    }

    .sidebar-overlay {
        transition: opacity 0.3s ease-in-out;
    }

    @media (max-width: 1024px) {
        .sidebar {
            transform: translateX(-100%);
        }

        .sidebar.open {
            transform: translateX(0);
        }
    }

    .menu-item {
        transition: all 0.2s ease;
    }

    .menu-item:hover {
        background: rgba(255, 255, 255, 0.1);
        transform: translateX(5px);
    }

    .menu-item.active {
        background: rgba(255, 255, 255, 0.15);
        border-left: 4px solid white;
    }
    </style>
    <link rel="stylesheet" href="assets/css/cooperative-theme.css">
    <link rel="stylesheet" href="assets/css/cooperative.css">
    <link rel="stylesheet" href="assets/css/cooperative-components.css">
    <style>:root{<?= $cooperativeBrand['css'] ?>}</style>
    <?php if ($cooperativeBrand['favicon_url']): ?><link rel="icon" href="<?= htmlspecialchars($cooperativeBrand['favicon_url'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
</head>

<body class="coop-shell bg-gray-50 min-h-screen">
    <a class="coop-skip" href="#main-content">Skip to main content</a>
    <!-- Sidebar -->
    <aside id="sidebar"
        aria-label="Primary navigation" tabindex="-1" class="sidebar fixed top-0 left-0 h-screen w-64 bg-gradient-to-b from-purple-800 to-indigo-900 text-white shadow-2xl z-50 overflow-y-auto">
        <div class="p-4">
            <!-- Logo -->
            <div class="flex items-center space-x-3 mb-8 pb-4 border-b border-white/20">
                <div class="coop-brand-mark">
                    <?php if ($cooperativeBrand['logo_url']): ?><img src="<?= htmlspecialchars($cooperativeBrand['logo_url'], ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><i class="fas fa-university text-white text-xl" aria-hidden="true"></i><?php endif; ?>
                </div>
                <div>
                    <h1 class="text-white text-lg font-bold"><?= htmlspecialchars($cooperativeBrand['name'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="text-white/80 text-xs">Management System</p>
                </div>
            </div>

            <button type="button" id="sidebar-close" class="coop-drawer-close lg:hidden" aria-label="Close navigation">Close menu <span aria-hidden="true">&times;</span></button>
            <!-- Menu Items -->
            <nav class="space-y-1" aria-label="Cooperative administration">
                <!-- Dashboard -->
                <a href="home.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'home.php' ? 'active' : '' ?>">
                    <i class="fas fa-home w-5"></i>
                    <span>Dashboard</span>
                </a>

                <a href="member_guarantor_evidence.php" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg"><i class="fas fa-file-signature w-5" aria-hidden="true"></i><span>Member evidence sign-in</span></a>

                <?php if (($canAdministerCooperative) || ($userRole == 'user')): ?>
                <!-- Process Loan -->
                <a href="loan-processor.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'loan-processor.php' ? 'active' : '' ?>">
                    <i class="fas fa-shopping-cart w-5"></i>
                    <span>Process Loan</span>
                </a>
                <?php endif; ?>

                <?php if ($canAdministerCooperative): ?>
                <!-- Loan Request Admin -->
                <a href="loan-request-admin.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'loan-request-admin.php' ? 'active' : '' ?>">
                    <i class="fas fa-money-bill-wave w-5"></i>
                    <span>Loan Request Admin</span>
                </a>

                <!-- Event Management -->
                <a href="event-management.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'event-management.php' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-alt w-5"></i>
                    <span>Event Management</span>
                </a>

                <!-- Reports -->
                <a href="masterReportModern.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'masterReportModern.php' ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar w-5"></i>
                    <span>Reports</span>
                </a>

                <!-- Enquiry -->
                <a href="enquiry.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'enquiry.php' ? 'active' : '' ?>">
                    <i class="fas fa-search w-5"></i>
                    <span>Enquiry</span>
                </a>

                <!-- Commodity -->
                <a href="procesCommodity.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'procesCommodity.php' ? 'active' : '' ?>">
                    <i class="fas fa-exchange-alt w-5"></i>
                    <span>Commodity</span>
                </a>

                <!-- Periods -->
                <a href="payperiods.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'payperiods.php' ? 'active' : '' ?>">
                    <i class="fas fa-table w-5"></i>
                    <span>Periods</span>
                </a>

                <!-- Process Deduction -->
                <a href="payprocess.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'payprocess.php' ? 'active' : '' ?>">
                    <i class="fas fa-cogs w-5"></i>
                    <span>Process Deduction</span>
                </a>

                <!-- Users -->
                <a href="users.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'users.php' ? 'active' : '' ?>">
                    <i class="fas fa-users w-5"></i>
                    <span>Users</span>
                </a>

                <!-- Records -->
                <a href="employee.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'employee.php' ? 'active' : '' ?>">
                    <i class="fas fa-user w-5"></i>
                    <span>Records</span>
                </a>

                <!-- Update Deductions -->
                <a href="update_deduction.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'update_deduction.php' ? 'active' : '' ?>">
                    <i class="fas fa-edit w-5"></i>
                    <span>Update Deductions</span>
                </a>

                <!-- Print List -->
                <a href="print_member.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'print_member.php' ? 'active' : '' ?>">
                    <i class="fas fa-print w-5"></i>
                    <span>Print List</span>
                </a>

                <!-- File Upload -->
                <a href="api_upload.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'api_upload.php' ? 'active' : '' ?>">
                    <i class="fas fa-cloud-download-alt w-5"></i>
                    <span>File Upload</span>
                </a>

                <!-- Bulk SMS -->
                <a href="coop_bulksms.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'coop_bulksms.php' ? 'active' : '' ?>">
                    <i class="fas fa-comment-dots w-5"></i>
                    <span>Bulk SMS</span>
                </a>

                <!-- Settings -->
                <a href="home.php#link_deletetransaction" id="sidebar_settings" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg">
                    <i class="fas fa-cog w-5"></i>
                    <span>Settings</span>
                </a>

                <!-- Accounting Section Divider -->
                <?php 
                if (isset($coop)) {
                    // Check if table exists using PDO
                    try {
                        $result = $coop->query("SHOW TABLES LIKE 'coop_accounts'");
                        $accountsExist = $result->rowCount() > 0;
                    } catch (PDOException $e) {
                         $accountsExist = false;
                    }
                } else {
                    $accountsExist = false;
                }
                if ($accountsExist): 
                ?>
                <div class="pt-4 pb-2 px-4">
                    <div
                        class="flex items-center space-x-2 text-white/60 text-xs font-semibold uppercase tracking-wider">
                        <i class="fas fa-calculator"></i>
                        <span>Accounting</span>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (($canAdministerCooperative) && $accountsExist): ?>
                <!-- Chart of Accounts -->
                <a href="coop_chart_of_accounts.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'coop_chart_of_accounts.php' ? 'active' : '' ?>">
                    <i class="fas fa-list-alt w-5"></i>
                    <span>Chart of Accounts</span>
                </a>

                <!-- Journal Entries -->
                <a href="coop_journal_entries.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'coop_journal_entries.php' || basename($currentPage) === 'coop_journal_entry_form.php' ? 'active' : '' ?>">
                    <i class="fas fa-book w-5"></i>
                    <span>Journal Entries</span>
                </a>

                <!-- Trial Balance -->
                <a href="coop_trial_balance.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'coop_trial_balance.php' ? 'active' : '' ?>">
                    <i class="fas fa-balance-scale w-5"></i>
                    <span>Trial Balance</span>
                </a>

                <!-- Financial Statements -->
                <a href="coop_financial_statements.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'coop_financial_statements.php' ? 'active' : '' ?>">
                    <i class="fas fa-file-invoice-dollar w-5"></i>
                    <span>Financial Statements</span>
                </a>

                <!-- General Ledger -->
                <a href="coop_general_ledger.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'coop_general_ledger.php' ? 'active' : '' ?>">
                    <i class="fas fa-book-open w-5"></i>
                    <span>General Ledger</span>
                </a>

                <!-- Member Statement -->
                <a href="coop_member_statement.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'coop_member_statement.php' ? 'active' : '' ?>">
                    <i class="fas fa-user-circle w-5"></i>
                    <span>Member Statement</span>
                </a>

                <?php if ($canAdministerCooperative): ?>
                <!-- Period Closing -->
                <a href="coop_period_closing.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'coop_period_closing.php' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-check w-5"></i>
                    <span>Period Closing</span>
                </a>
                <?php endif; ?>

                <!-- Bank Reconciliation -->
                <a href="coop_bank_reconciliation.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'coop_bank_reconciliation.php' ? 'active' : '' ?>">
                    <i class="fas fa-building w-5"></i>
                    <span>Bank Reconciliation</span>
                </a>

                <!-- Comparative Reports -->
                <a href="coop_comparative_reports.php"
                    class="menu-item flex items-center space-x-3 px-4 py-3 rounded-lg <?= basename($currentPage) === 'coop_comparative_reports.php' ? 'active' : '' ?>">
                    <i class="fas fa-chart-line w-5"></i>
                    <span>Comparative Reports</span>
                </a>
                <?php endif; ?>
            </nav>
        </div>
    </aside>

    <!-- Sidebar Overlay (for mobile) -->
    <div id="sidebar-overlay" class="sidebar-overlay fixed inset-0 bg-black/50 z-40 hidden lg:hidden"></div>

    <!-- Header -->
    <header class="gradient-bg shadow-lg fixed top-0 left-0 right-0 z-30 lg:left-64">
        <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <!-- Mobile Menu Toggle -->
                <button type="button" id="menu-toggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false"
                    class="lg:hidden text-white hover:bg-white/20 p-2 rounded-lg transition-colors">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <!-- Page Title -->
                <div class="flex-1 text-center lg:text-left lg:ml-4">
                    <h2 class="text-white text-lg font-semibold"><?= htmlspecialchars($pageTitle) ?></h2>
                </div>

                <!-- User Info and Actions -->
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <!-- User Info -->
                    <div class="hidden sm:block text-white text-sm">
                        <i class="fas fa-user-circle mr-1"></i>
                        <?= htmlspecialchars($userName) ?>
                        <span class="text-white/80 text-xs">(<?= htmlspecialchars($userRole) ?>)</span>
                    </div>

                    <!-- Logout Button -->
                    <a href="logout.php" aria-label="Log out"
                        class="bg-white/20 hover:bg-white/30 text-white px-3 py-2 rounded-lg transition-colors text-sm">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="hidden sm:inline ml-1">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Container -->
    <main id="main-content" tabindex="-1" class="pt-20 lg:ml-64 px-4 sm:px-6 lg:px-8 py-8">

        <script>
        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menu-toggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');

            function openSidebar() {
                sidebar.classList.add('open');
                sidebar.removeAttribute('inert');
                menuToggle.setAttribute('aria-expanded', 'true');
                sidebar.focus();
                sidebarOverlay.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }

            function closeSidebar() {
                const wasOpen = sidebar.classList.contains('open');
                sidebar.classList.remove('open');
                sidebar.toggleAttribute('inert', window.innerWidth < 1024);
                menuToggle.setAttribute('aria-expanded', 'false');
                if (wasOpen && window.innerWidth < 1024) menuToggle.focus();
                sidebarOverlay.classList.add('hidden');
                document.body.style.overflow = '';
            }

            menuToggle?.addEventListener('click', function() {
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });

            sidebarOverlay?.addEventListener('click', closeSidebar);
            document.getElementById('sidebar-close')?.addEventListener('click', closeSidebar);
            sidebar.toggleAttribute('inert', window.innerWidth < 1024);
            document.querySelectorAll('#sidebar a.active').forEach(link => link.setAttribute('aria-current', 'page'));
            document.addEventListener('keydown', function(event) {
                if (!sidebar.classList.contains('open')) return;
                if (event.key === 'Escape') { event.preventDefault(); closeSidebar(); }
                if (event.key === 'Tab') {
                    const items = Array.from(sidebar.querySelectorAll('a[href], button')).filter(el => el.getClientRects().length);
                    const first = items[0], last = items[items.length - 1];
                    if (event.shiftKey && (document.activeElement === first || document.activeElement === sidebar)) { event.preventDefault(); last?.focus(); }
                    else if (!event.shiftKey && (document.activeElement === last || document.activeElement === sidebar)) { event.preventDefault(); first?.focus(); }
                }
            });

            // Close sidebar on window resize to desktop
            window.addEventListener('resize', function() {
                closeSidebar();
            });

            // Settings modal trigger from sidebar
            document.getElementById('sidebar_settings')?.addEventListener('click', function(e) {
                const trigger = document.getElementById('link_deletetransaction');
                if (trigger) {
                    e.preventDefault();
                    closeSidebar();
                    trigger.click();
                }
            });
        });
        </script>
