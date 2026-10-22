# Shared glass interface system

Owner direction: modern glass styling for menus, buttons, layouts and components; configurable cooperative colors/logo/favicon per separately deployed cooperative/database; Auracle Innovations Limited credit at https://www.auracle.ng. Configuration writes require a separately provisioned Auracle platform identity, not an ordinary cooperative administrator.

## Reuse map

`includes/header.php` and `includes/footer.php` provide the authenticated shell for existing PHP pages. `assets/css/cooperative-theme.css` owns semantic tokens and reusable `.coop-glass`, `.coop-card`, `.coop-button`, `.coop-field`, `.coop-notice` and `.coop-table` primitives. `assets/css/cooperative.css` adapts existing navigation, dashboard, loan, journal and table classes to those tokens. `assets/css/cooperative-components.css` holds the previously duplicated holding/product/evidence form styles and standalone sign-in/platform layouts. `includes/software_credit.php` is the shared exact vendor credit, without an invented vendor logo.

Holding, loan-product and evidence forms no longer carry independent inline palettes. Their native form controls, validation, permission gates and server-backed data remain the existing implementations. Member evidence and platform sign-in use the local shared CSS without the staff-session header. Existing CDNs remain legacy dependencies; this change adds no CDN, package or framework.

## Tokens and visual behavior

All new component colors use semantic variables: primary/accent/on-primary, text/muted/link, danger/success/warning, borders, canvas/surface/nav and focus. Branding may override validated color tokens; foreground/link/focus must remain contrast-safe rather than blindly treating a configured light brand as readable text. The bundled neutral indigo palette is a display fallback, not a persisted cooperative choice.

Glass uses a static tinted canvas, translucent near-white panels, fine borders and restrained inset/elevation shadows. Text and inputs retain strong readable surfaces. Only the fixed header uses backdrop blur by default to limit repeated expensive filters. Unsupported blur has a readable solid/translucent fallback. Reduced transparency and slow-update media remove blur/tinted backgrounds; reduced motion removes animation/transitions; forced colors use system canvas/text boundaries. Controls retain 44px targets and visible focus. Existing responsive sidebar behavior and grid/table overflow containment remain in place.

## Verification scope

`tests/cooperative_theme_test.cjs` measures default token color pairs: primary/on-primary 8.37:1; body/surface 15.48:1; muted/surface 7.15:1; navigation text/background 15.30:1; tested field boundaries/focus exceed 3:1. These are calculated token pairs, not measured rendered pixels. Configurable palettes need their own backend derivation/validation and tests.

`tests/cooperative_shell_page_test.php` renders the actual shared header/footer with synthetic authenticated sessions, checks local CSS loading, skip link/menu controls, role-aware navigation, escaping and exact software credit. Existing holding/product/evidence/member DOM and PHP render suites verify those interactions after style extraction.

No actual viewport, native keyboard, screen-reader or rendered accessibility pass is claimed: the supported browser surface was unavailable. Optional a11y skill checklist/render resources were absent. Old standalone pages not using the shared shell still require an explicit inventory and migration; shared CSS adoption is not a claim that every legacy screen is redesigned. Brand/integration editor delivery is tracked separately with its backend contract.

## Branding editor and runtime rendering

Delivered `platform_login.php` and `platform_settings.php` use a separate provisioned Auracle platform session. The editor checks `requireAuraclePlatformPrincipal()` before any settings markup; a cooperative Admin session supplies no fallback. Public name/colors/clean assets are read through `deploymentPublicBranding()` and rendered via `includes/branding_view_helper.php`, which accepts strict six-digit HEX and controlled asset URLs, selects contrast-safe on-primary/link/focus/nav tokens, and escapes display names. Configured logo/favicon/name/colors reach the shared staff shell, staff sign-in and member evidence page. Backend authorization, encrypted secrets, image scanning and runtime integrations belong to the separate deployment configuration services.

The editor connects exact branding and SMTP/Paystack/custom-SMS fields to the documented API. Secrets have explicit retain/replace/clear actions; replacement plaintext is never stored in browser storage or populated from a response. Images are staged with an explicit later branding save, actual 5 MiB bound and configured scanner requirement. Custom SMS can be saved but its adapter remains unimplemented and sending unavailable. Separate SMTP/Paystack checks use saved configuration and never send mail/create payments; `network_tested:false` is displayed as untested.

Before mutations, the UI stores a stable command key, opaque fingerprint and original expected revision in tab storage. Lost acknowledgement replay preserves that revision after reload; a changed payload requires an explicit new-command confirmation. Asset fingerprints bind actual content/actor/kind. No raw file, filename, token or plaintext integration secret is persisted. Secure WebCrypto/storage failures stop submission. This protects page-refresh retries while tab storage remains intact; server receipts remain authoritative. Requests have 30-second bounds and do not imply rollback on abort.

Additional checks: platform render fixture 3; branding palette/injection checks 2; platform login DOM suite; platform editor DOM suite covering refresh/revision replay, changed payload, storage failure, secret actions, connection-test truthfulness, image upload, logout and timeout/busy recovery. Render tests inject synthetic auth/public-branding dependencies and do not touch application databases. These checks do not replace backend HTTP/MariaDB tests or a rendered browser audit. No dark-theme acceptance is claimed; this change implements the approved glass direction with readable light surfaces and platform accessibility fallbacks.

## Remaining standalone migration candidates

A source heuristic (`<html`/`<body` without the current shared header or theme link) found 29 candidates, including generated email/report output and backup files. Main interactive candidates requiring explicit reachability and migration review are `loan/index.php` and `loan/views/{member-account-update,beneficiary-management,batch-management}.php`; `dividend/{index,member_dividends,pro_rated_dividends}.php`; `update/{index,change_password}.php`; `auth_api/admin/{login,index,audit_trail,approve_changes}.php`; `auth_api/admin_duty/{login,dashboard,view_duties}.php`; `bank_statement_upload.php`; `bank_statement_history.php`; and `excel_import/{index,upload}.php`. Source presence alone does not establish deployed reachability. Print exports, generated emails and backup templates need separate treatment rather than blindly applying navigation/CSS. This list prevents the shared-shell delivery from being mistaken for a completed redesign of every legacy screen.
