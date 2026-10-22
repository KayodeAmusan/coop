# Cooperative platform: active implementation handoff

Updated: 21 October 2026 (Africa/Lagos).
Status: ACTIVE. The user requested this progress update; they did not ask to pause.
Repository: C:\Users\kaygo\coop
Branch: feature/cooperative-consent-foundations
Upstream base: e0352011178d508fcbf1eaf16369304ff64bfd71 (master).
Changes remain local and uncommitted. No production migrations, payments, messages or deployment have been performed.

## Mission and working rules

Complete the original cooperative platform brief, not just the loan slice. Original brief:
C:\Users\kaygo\.codex\attachments\8967b685-9e76-46c1-b0bb-d2ffd03b0239\Pasted text.txt

Preserve native PHP/PDO/MySQL. Prioritize financial correctness/privacy, complete workflows, accessible member experiences, low-end phones/network performance and reuse.
The user makes final feature/business-policy decisions: ask about genuine uncertainty and do not silently invent rates, eligibility, fees, providers or authority.
Search and reuse existing functions/services/components before adding code. Keep UI composable and connected to real backend capabilities.
Ponytail remains active. Relevant backend, database, architecture, frontend/UI/UX, accessibility and QA skills have been applied. Graphify was unavailable; source/tree inspection was the disclosed fallback.
Use specialist agents with distinct ownership and shared contracts. Four concurrent slots include root.

## Confirmed owner decisions

1. Each cooperative has a SEPARATE deployment and database; do not introduce shared multi-tenant hosting.
2. New loan applications require explicit configured products first. Legacy two-guarantor/10%-principal repayment values are not approved new-product defaults.
3. Support all six approved loan methods: interest-free; flat; reducing balance with equal installments; reducing balance with equal principal; interest-only with final principal payment; manually reviewed installment schedule. Rates, duration, fees, rounding and other policies must be explicitly configured.
4. Third-party loans include nonmember borrowers AND staff-assisted existing members without internet access, as distinct cases. Nonmember guarantor eligibility is separately configured per product.
5. Deduction allocation remainder, INCLUDING rounding residue, is held unallocated for review. An administrator must explicitly select its existing holding ledger account during setup; do not create/invent an account.
6. Uploaded signed guarantor approval proof is the REQUIRED gate for online and offline cases. Borrower, named guarantor or authorized staff may upload. Cooperative staff must accept/reject before loan approval.
7. A DIFFERENT staff member must review a staff-uploaded guarantor document.
8. Decision 6 explicitly supersedes the original brief's mandatory direct-authenticated guarantor click. Preserve named guarantor, uploader and reviewer as different identities; never fabricate guarantor-authenticated provenance.
9. Idempotency is mandatory across the SYSTEM, not just journals/uploads. Current coverage is partial; do not claim every legacy mutation is protected.
10. Approved UI direction: modern glassmorphism across menus, buttons, layouts and objects. Cooperative colours, name, logo and favicon must be configurable through shared reusable components/tokens.
11. Software attribution: Auracle Innovations Limited, https://www.auracle.ng.
12. ONLY Auracle platform administrators configure branding and integration connections (SMTP, Paystack, SMS). Cooperative Admin/SuperAdmin labels do not imply platform authority.
13. Auracle administrators use a SEPARATE login provisioned during deployment, with no default account or automatic elevation.
14. AUTHORIZED COOPERATIVE ADMINISTRATORS manage cooperative staff roles/permissions. Cooperative access and Auracle platform identity remain separate.
15. SMS endpoint/API credentials/request settings should be configurable. The owner permits completing provider-specific adapter code later. Do not assume a vendor or report an unfinished adapter as connected/sending.
16. Notifications must support personalization, Jinja/HTML/plain-text templates, and creation of additional templates in the UI. This requirement is recorded; its implementation is not complete.

## Completed foundations and delivered workflows

### Financial correctness and security
- Shared integer-kobo money helpers; exact ledger/member/account-balance decisions, signed decimals, overflow guards.
- AccountingEngine transaction/savepoint ownership, atomic audit/post/reversal, locked persisted-line checks, concurrent journal numbering, creator-scoped keyed journal creation.
- Same journal ID posting/reversal is safely repeatable. Keyed creation rejects changed payloads.
- Closing, bank reconciliation, member-period initialization and member-account writes hardened for exactness, rollback and concurrency.
- Journal API/form use stable submission keys and exact decimal inputs; create+post is atomic.
- Bank reconciliation now requires stable command identity, canonical payload hash, original stored result and adjustment journal links; replay works after book/period changes. UI preserves pending payload/key across timeout/reload.
- Financial APIs enforce administrator/method/origin checks; book-balance errors cannot masquerade as successful zero.
- Payroll processing is administrator-only POST with origin/input checks before DB; actual caller updated.
- Savings/share allocations use exact conservation; underallocation/rounding residue is held with member/period/source and posted-journal trace. Missing holding setup fails before posting.
- Holding account setup UI/API validates existing active posting accounts and audits changes.
- JWT signing secret fallback removed; token signature/header/identity/time claims hardened. Credential/token logging removed in integrated login/wallet paths.
- Import autoload paths repaired to root vendor. Composer lockfile retained; audit reported no known security vulnerability advisories at the last run.

### Loan products and signed guarantor evidence
- New member/manual applications explicitly blocked until complete product configuration; no invented legacy defaults.
- Draft product creation, immutable candidate versions, all-six-method server preview and administrator draft UI.
- Draft commands have atomic audit/idempotency receipts. Product publication is deliberately unavailable while the full workflow is unfinished.
- Pure exact six-method calculator with explicit policy inputs; fee handling/calendar/rate conversion not falsely claimed.
- Private PDF/JPEG/PNG guarantor evidence uploads, immutable versions/history, actual size/type/hash validation, configured malware scan boundary and authorized attachment downloads.
- Missing/unavailable scanner means quarantine: no review/download/approval bypass.
- Member JWT and staff session upload/list/download routes; staff review/rescan routes. Borrower/guarantor/staff actor provenance is preserved.
- Different staff uploader/reviewer enforcement, pending-to-final reviews, identical retry no-op, changed decision requires new version/controlled workflow.
- Latest clean, current-terms, staff-accepted signed approval is required for EACH current guarantor in the approval predicate. Old accepted evidence cannot be used behind newer pending/rejected/stale proof.
- Direct clicks alone cannot qualify. Upload/review and approval share loan/invitation locking and authoritative status/count predicates.
- Actual multipart HTTP tests exercise independent concurrent clients and a lost commit acknowledgement: one intended upload remains one file/version.
- Member evidence page uses existing login, memory-only JWT, own-loan/invitation discovery and private downloads; staff/member pages reuse shared view/controller.
- Upload identities persist across refresh using only opaque key/content fingerprint; no JWT, secret, filename or file bytes are persisted by that mechanism.

### UI and branding foundation
- Shared glass theme/tokens/components and responsive shell; extracted shared form styles for product, holding and evidence pages.
- Configurable public branding renderer, accessible foreground/focus derivation and Auracle vendor credit.
- Real dashboard shortcuts/data states, guarded financial widgets; no invented balances/activity.
- Keyboard/focus handling, visible labels/errors, reduced-motion/transparency fallbacks and bounded request timeouts.
- Separate platform login/settings screens wired to delivered backend contracts: branding/assets, SMTP/Paystack/custom-SMS fields, masked secret retain/replace/clear, revision and stable command keys.
- Platform UI and branding render/DOM checks passed individually. Real browser rendering remains unverified.
- Many standalone legacy screens still need migration; see GLASS_UI_SYSTEM.md. Do not claim the entire UI is glass-complete.

### RBAC and deployment configuration (partially integrated)
- CooperativeAccessService, independent role/permission/membership schema and guarded administration API exist.
- Live active-staff capability resolution, deny-by-default grants, idempotent audited role commands, last-role-editor protection and concurrent revocation checks.
- No automatic Admin-to-capability or Admin-to-Auracle conversion.
- api/users.php unauthenticated credential exposure/arbitrary writes contained: guarded safe reads; legacy writes temporarily unavailable until audited lifecycle replacement.
- Separate platform identity/throttling/session-version implementation, CLI provisioning tool, encrypted settings/revision/keyed commands, branding asset/API helpers exist.
- AES-256-GCM credentials require an explicit external deployment key; UI/audits/receipts do not disclose plaintext.
- SMTP connect/auth/quit and fixed Paystack read-only connection tester implemented; no real credentials/provider traffic used during tests.
- SMS explicitly reports adapter pending/configuration-only.
- These newer platform/RBAC pieces have individual tests, but still require final integration, runtime bindings and aggregate verification.

## Verification: exact evidence, not completion claims

Last completed COMBINED gate captured by root:
C:\xampp\php\php.exe work/run-evidence-with-db.php tests/run.php --mysql
Result: 41 scripts, 443 reported PASS checks, ZERO failures, ZERO skipped database scripts.

That checkpoint includes signed-evidence HTTP/concurrency/lost-ack tests, six loan methods, bank idempotency, payroll, member UI contracts and shared glass foundations.
It predates the newest platform configuration/RBAC/template work. Do not present 443 as a current full-system certification.
Earlier broad PHP syntax gate: 288 files, zero failures; many files were added afterward and need a fresh broad lint.
Individual later agent-reported checks:
- Cooperative RBAC: 13 real MariaDB checks; users boundary: 2.
- Platform configuration: 6 pure checks and 9 real MariaDB domain/auth checks; actual platform HTTP/cookie/asset tests still being completed.
- Platform UI: 3 PHP render, 2 branding pure checks, login/settings DOM suites; shared shell/form regressions passed.
- Bank workflows: 24 real DB checks including command race/replay/faults; route boundary and UI retry checks passed.
- Guarantor evidence: 20 real HTTP/PDO and 8 storage checks passed.
Do not add these independent counts together as a new combined result.

Supported browser inventory was empty; in-app browser creation failed. No viewport/native-keyboard/full-browser accessibility or slow-network acceptance claim is valid.
Tests use only synthetic coop_test_* schemas on loopback port13307. No production data used.

## Remaining work: next integration steps

1. Finish platform actual HTTP/auth/asset tests, review integration and register new platform/RBAC test opt-in flags in tests/run.php. Unexpected selected-test SKIP now fails the gate.
2. Bind ALL legacy email/notification runtime callers to encrypted UI configuration. Root accepted ownership but has NOT done it:
   - config/EnvConfig.php: missing getMailConfig via deployment helper.
   - EmailQueueManager PHPMailer autoload and mysqli/PDO mismatch.
   - auth_api/utils/EmailService and both NotificationService variants.
   Reuse includes/deployment_settings_helper.php; do not intercept generic EnvConfig::get SMTP keys recursively. No existing hardcoded credential may remain an active fallback.
3. Remove/replace legacy hardcoded SMTP/SMS/push credentials without printing them; actual credentials require rotation before deployment. Never reuse/repeat values.
4. Complete cooperative CLI bootstrap naming an explicitly selected active staff ID (root approved this after owner's role-management answer), role editor UI, audited user lifecycle, and migrate business routes to capabilities. Legacy exact-Admin checks remain a limited compatibility boundary, not complete RBAC.
5. Implement personalized notification template/workflow UI, immutable versions/preview, allowed context and safe rendering for requested Jinja/HTML/plain text. Full Jinja2 versus PHP-compatible syntax has NOT been decided or implemented; clarify compatibility instead of silently substituting a different engine.
6. Complete notification trigger/channel composition, queue producer identities/worker leases/outbox and retry behavior. Provider-specific SMS adapter may remain clearly unavailable per owner; never return fake send success.
7. Finish system-wide idempotency gaps documented in IDEMPOTENCY_AUDIT.md: payroll immutable run/revision receipts, deductions/controller writes, imports/batches, alternate loan stacks, member/user commands, period closing command outcomes, jobs/provider sends and remaining aliases.
8. Complete loan product publishing/configuration, fees, actual due dates/rate conversions, eligibility/exposure/acceptance/amendments/revocation, nonmember identity/assisted applications and disbursement checks across every path. Calculator/draft UI is not a complete loan workflow.
9. Finish remaining brief modules: membership/onboarding, configurable savings, deposit verification/reconciliation workbench, refunds/withdrawals/exit, conserved shares/transfers, frozen bonuses, private documents/bulk imports, secret elections, notices/ads, reports/jobs/performance.
10. Migrate remaining standalone legacy screens to shared glass components; real browser/mobile/accessibility acceptance still required.
11. Final aggregate QA, broad syntax/diff checks, source/dependency completeness, migration/reconciliation/staging acceptance, reviewable commits. No push/PR/deployment has occurred.

## Pending owner policy / setup

- Deduction excess after required loan repayment: owner question remains unanswered. Do not silently change/approve the legacy savings behavior.
- Exact Jinja compatibility/renderer and template publication/delivery permissions need clarification/implementation.
- Rates/fees/eligibility/exposure/election/bonus/exit/correction/import policies remain explicit setup decisions from the feature matrix; do not invent values.
- Private storage/scanner and platform encryption key/provisioned platform account must be configured during deployment. No real scanner, encryption key or platform account has been provisioned.
- Do not request passwords/API keys in chat.

## Safety restrictions and known limitations

classes/delete.php now refuses legacy deletion BEFORE database access: it previously deleted payroll markers without reversing posted journals, allowing duplicate posting on rerun.
Automatic approval review rejected a proposed broader deletion reroute due to its destructive scope. The safer blocking alternative was implemented and tested. Do not bypass that rejection or silently restore deletion; controlled correction/deletion remains unfinished.
Legacy parallel loan/financial/import routes still require audit/migration; do not claim non-bypassable policy/idempotency everywhere yet.
Recovered checkout has exact upstream commit/tree but some unchanged unavailable assets/TCPDF/downloads remain skip-worktree. Root .env/full application config is absent. Full application readiness is not established.
Generated work/ fixtures/logs/local wrappers must be excluded from commits; check .gitignore (work/ was still untracked at last status).
No member notification, external payment, production migration or secret rotation was executed.

## Current agent ownership

- root: operations/integration, this handoff, owner decisions, final review/QA; legacy email/notification runtime bindings accepted but not done.
- loan_creation: platform encrypted settings/auth/bootstrap/APIs/assets/connection tester and actual HTTP tests. Previously delivered payroll/JWT/guarantor storage workflow.
- consent_review: cooperative RBAC/bootstrap/audited user lifecycle; previously completed bank command idempotency and signed-evidence approval integration.
- ledger_review: shared glass components, branding renderer/platform editor and tests; may coordinate cooperative role-editor UI with consent_review.
- Earlier UX/architecture specialists delivered UX_JOURNEYS.md and ARCHITECTURE_CONTRACTS.md.
Avoid overlapping edits; coordinate new shared files/contracts first.

## Environment and run commands

PHP: C:\xampp\php\php.exe (8.2.12; BCMath available; GD/ZIP available but enabled only via per-command flags where needed).
Git: C:\Program Files\Git\mingw64\bin\git.exe (cmd wrapper sometimes fails).
Composer: C:\Users\kaygo\AppData\Local\ComposerSetup\bin\composer.phar.
Disposable MariaDB10.4.32: 127.0.0.1:13307, root/test empty password; ONLY coop_test_* schemas.
Existing test config: C:\Users\kaygo\Documents\Codex\2026-10-21\new-chat\work\mariadb-test\my.ini.
Servers expire between tool commands; use work/run-evidence-with-db.php to retain only its own server through a test run. Never initialize/delete/repoint production data.
Windows security blocks mysqladmin.exe; do not bypass. PHP PDO and the owned process wrapper are the test clients.
Default sandbox helper intermittently fails; approved escalated commands are used for the user-authorized C:\Users\kaygo\coop repository.
tests/run.php without --mysql deliberately skips database scripts. --mysql requires all selected test flags and isolated server; a selected script silently skipping is now a failure.

## Key documents

IMPLEMENTATION_AUDIT.md — whole-brief matrix and baseline.
IMPLEMENTATION_PROGRESS.md — progress, needs synchronization with this newer handoff.
LOAN_PRODUCT_CONTRACT.md — supported calculations/draft contract and publication gaps.
docs/implementation/ARCHITECTURE_CONTRACTS.md
docs/implementation/UX_JOURNEYS.md
docs/implementation/IDEMPOTENCY_AUDIT.md
docs/implementation/GUARANTOR_EVIDENCE.md
docs/implementation/MEMBER_EVIDENCE_UI.md
docs/implementation/GLASS_UI_SYSTEM.md
docs/implementation/COOPERATIVE_RBAC_CONTRACT.md
docs/implementation/QA_RESULTS.md — older saved checkpoint; update from the verified 443 result and newer final gate.

## Continuation checkpoint: 22 October 2026
- User reaffirmed full-project completion, task-appropriate skills and progress recording in AGENTS.md. AGENTS.md is now the canonical handoff; agent.md preserves the prior checkpoint.
- Applied Chief of Staff/context recovery and Ponytail full. Recovered the existing priorities without changing approved business policy.
- Registered COOP_PLATFORM_MYSQL_TEST, COOP_ACCESS_MYSQL_TEST and COOP_EVIDENCE_MYSQL_TEST in tests/run.php. The aggregate runner can now select platform configuration HTTP/domain, cooperative RBAC and evidence tests without relying on wrapper-inherited opt-ins.
- Verified broad PHP syntax: 337 project PHP files, zero failures (vendor/work/.git excluded).
- Combined database gate attempted through work/run-evidence-with-db.php tests/run.php --mysql. No result available yet; sandbox launch has produced no output and an outside-sandbox rerun was requested. Do not claim a current aggregate pass.
- Next: obtain the combined gate result, fix any failures, then bind legacy email/notification runtime callers to encrypted deployment settings (remaining-work item 2 above).
- No deployment, production migration, external notifications, payments or commits performed.
- Sandbox combined attempt finished: 51 scripts, 267 reported PASS checks, 16 failed scripts, zero skips. Database connections were refused; PHP also reported a chdir path error and one private-storage assertion failed. These require diagnosis; outside-sandbox rerun remains in progress (session 36779). This is a failed gate, not acceptance.
- VERIFIED outside-sandbox combined gate completed successfully: 51 scripts, 492 reported PASS checks, ZERO failures, ZERO skipped database scripts. Includes platform actual HTTP/auth/assets, encrypted configuration, cooperative RBAC, financial and guarantor evidence checks. The earlier sandbox-only path/storage/database failures did not reproduce; no security checks were weakened.
- Current verified baseline supersedes the historical 443-check checkpoint for this checkout. Real browser/mobile acceptance, production configuration and full-platform feature completion remain outstanding.

## Independent resume and GitHub publication — 22 October 2026

Preserve the working rules and historical checkpoints above. Current independently verified progress, corrections, gaps and next actions are in IMPLEMENTATION_PROGRESS.md and docs/implementation/RESUME_AUDIT.md with granular financial/workflow/UX matrices. Original specification is now copied into docs/implementation/ORIGINAL_SPECIFICATION.md for portable continuation.

Fresh pre-edit baseline51 scripts/492 checks was rerun. After member-isolation, legacy loan containment, notification configuration/privacy and credential corrections: combined gate58 scripts/526 reported PASS checks, zero failures/skips; broad PHP lint344 files, zero failures. These cover selected synthetic workflows, not complete original acceptance. Unsupported legacy loan mutation/disbursement stays unavailable pending canonical replacement.

Owner explicitly requested KayodeAmusan/coop and this computer's authentication. Local Git Credential Manager login for KayodeAmusan succeeded. Reviewed source snapshot455 files published as commit0ead8e3d78d7a0dfc1487a24d010eb2c5be619a4 (tree0cb9da08df00bcf80483f1215e70789f5e417667) to feature/cooperative-consent-foundations. Every remote file hash verified. Main included the snapshot through PR1 merge a720699f3fa00bf2ae89ad67e26215e8fa6b48b6; that merge was preserved. Publication-record commit5bc89137680df400d809454eff3bd8f5f6a546cd was verified on both branches with456 files. ChatGPT connector403 was bypassed by using the expressly requested local account, not another connector identity. Original local origin/branch/history/dirty changes remain preserved. Credential-bearing old history, private/runtime files, vendor/work artifacts are excluded from publication. 422 unavailable skip-worktree dependencies/assets remain disclosed. Exposed old credentials require rotation before deployment; no real sends/payouts/production migration/deployment performed.

Next concrete implementation action: follow the dependency backlog in RESUME_AUDIT.md, starting with remaining financial/authorization/private-document boundaries and complete canonical workflows. Do not re-enable contained legacy loan writes merely to make screens usable.
