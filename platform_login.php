<?php
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Auracle platform sign-in</title><link rel="stylesheet" href="assets/css/cooperative-theme.css"><link rel="stylesheet" href="assets/css/cooperative-components.css"></head>
<body class="coop-public"><main class="platform-workspace">
<section class="coop-glass" aria-labelledby="platform-login-heading">
<p class="platform-eyebrow">AURACLE PLATFORM ADMINISTRATION</p><h1 id="platform-login-heading">Deployment settings sign-in</h1>
<p>Sign in with your separately provisioned Auracle platform account to manage this cooperative deployment's branding and integrations.</p>
<form id="platform-login-form">
<label for="platform-username">Platform username</label><input class="coop-field" id="platform-username" name="username" autocomplete="username" required maxlength="190">
<label for="platform-password">Password</label><input class="coop-field" id="platform-password" name="password" type="password" autocomplete="current-password" required>
<button class="coop-button" id="platform-login-submit" type="submit">Sign in to deployment settings</button>
<p id="platform-login-status" role="status" aria-live="polite" tabindex="-1"></p>
</form><p><a href="index.php">Cooperative staff sign-in</a> · <a href="member_guarantor_evidence.php">Member evidence sign-in</a></p>
</section><?php require __DIR__.'/includes/software_credit.php'; ?></main>
<script src="assets/js/platform_login.js"></script></body></html>
