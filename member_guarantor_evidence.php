<?php
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$initialLoan = '';
$evidenceStaff = false;
require_once __DIR__.'/includes/branding_view_helper.php';
$cooperativeBrand=cooperativeBrandingView();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= htmlspecialchars($cooperativeBrand['name'],ENT_QUOTES,'UTF-8') ?> · Member evidence</title><link rel="stylesheet" href="assets/css/cooperative-theme.css"><link rel="stylesheet" href="assets/css/cooperative-components.css"><style>:root{<?= $cooperativeBrand['css'] ?>}</style><?php if($cooperativeBrand['favicon_url']): ?><link rel="icon" href="<?= htmlspecialchars($cooperativeBrand['favicon_url'],ENT_QUOTES,'UTF-8') ?>"><?php endif; ?></head>
<body class="coop-public">
<main class="evidence-review">
<div class="platform-brand-identity"><?php if($cooperativeBrand['logo_url']): ?><span class="coop-brand-mark"><img src="<?= htmlspecialchars($cooperativeBrand['logo_url'],ENT_QUOTES,'UTF-8') ?>" alt=""></span><?php endif; ?><p><?= htmlspecialchars($cooperativeBrand['name'],ENT_QUOTES,'UTF-8') ?></p></div>
<section id="member-login-section" class="evidence-card" aria-labelledby="member-login-heading">
<h1 id="member-login-heading">Member evidence sign-in</h1>
<p>Use your existing member account. Your login remains in memory for this page; refreshing requires sign-in again.</p>
<form id="member-login-form">
<label for="member-username">Coop ID, email or phone number</label><input id="member-username" autocomplete="username" required maxlength="254">
<label for="member-password">Password</label><input id="member-password" type="password" autocomplete="current-password" required>
<button id="member-login-submit" type="submit">Sign in</button>
</form><p id="member-login-status" role="status" aria-live="polite" tabindex="-1"></p>
</section>
<div id="member-workspace" hidden>
<section class="evidence-card" aria-label="Your member account and loans"><p id="member-identity"></p><button id="member-signout" type="button">Sign out of this page</button>
<label for="member-loan-choice">Your loan or guarantor invitation</label><select id="member-loan-choice"><option value="">Sign in to load your loans</option></select>
<button id="member-refresh-loans" type="button">Reload my loans and invitations</button><p id="member-workspace-status" role="status" aria-live="polite" tabindex="-1"></p></section>
<?php require __DIR__.'/includes/guarantor_evidence_view.php'; ?>
</div></main><footer><?php require __DIR__.'/includes/software_credit.php'; ?></footer><script src="assets/js/guarantor_evidence.js"></script></body></html>
