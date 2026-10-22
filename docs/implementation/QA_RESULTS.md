# QA results — 21 October 2026
Working repository: C:\Users\kaygo\coop.

## Independent resume checkpoint — 22 October 2026

Before new application edits: 51 scripts, 492 reported PASS checks, zero failures/skips. After member-isolation, legacy-loan containment, runtime notification/configuration and publication credential corrections: **58 scripts, 526 reported PASS checks, zero failures, zero skipped database scripts**. Both runs used C:/xampp/php/php.exe work/run-evidence-with-db.php tests/run.php --mysql outside sandbox with synthetic coop_test_* schemas on loopback13307. No provider sends or production data.

This current checkpoint supersedes historical counts below. See IMPLEMENTATION_PROGRESS.md and RESUME_AUDIT.md for exact scope and remaining features. Actual browser/mobile/performance, full fresh-schema setup/restore and historical ledger reconciliation remain unverified.

Final broad syntax check: 344 PHP files excluding vendor/work/.git, zero failures. Whole dirty-tree diff check flags legacy whitespace, including payprocess.php not edited in this resume; formatting was not rewritten to manufacture a clean diff. Original source snapshot excludes old history, private/runtime files and generated fixtures.

## Last completed combined checkpoint (before guarantor-document policy revision)
Command: C:\xampp\php\php.exe tests/run.php --mysql

**26 scripts, 335 reported PASS checks, zero failed scripts, zero skipped database scripts.**
Syntax gate: **288 PHP files checked, zero syntax failures** (vendor excluded).
A subsequent isolated holding-UI timeout change passed its Node regression and PHP syntax check. New draft-product API/UI and mandatory signed-evidence changes are in progress; they require a fresh combined gate before this checkpoint can represent the latest implementation.

| Script | Reported checks |
|---|---:|
| account_balance_database_test.php | 6 |
| accounting_engine_database_test.php | 21 |
| accounting_engine_test.php | 6 |
| accounting_workflows_database_test.php | 16 |
| admin_access_test.php | 12 |
| admin_mutation_origin_test.php | 10 |
| book_balance_api_test.php | 4 |
| consent_migration_mysql_test.php | 4 |
| dashboard_access_test.php | 7 |
| guarantor_status_database_test.php | 16 |
| import_response_test.php | 8 |
| jwt_security_test.php | 38 |
| loan_application_mysql_test.php | 16 |
| loan_consent_database_test.php | 12 |
| loan_consent_test.php | 54 |
| loan_product_mysql_test.php | 8 |
| loan_schedule_calculator_test.php | 18 |
| member_account_database_test.php | 10 |
| member_identity_test.php | 7 |
| payroll_database_test.php | 42 |
| payroll_holding_endpoint_test.php | 8 |
| payroll_request_boundary_test.php | 8 |
| journal_form_ui_test.cjs | 1 suite |
| loan_admin_ui_test.cjs | 1 suite |
| payroll_holding_ui_test.cjs | 1 suite |
| payroll_request_ui_test.cjs | 1 suite |

These are script-reported PASS counts, not a coverage percentage. JavaScript suite counts summarize multiple assertions.

## Environment and safety
PHP8.2.12 (BCMath enabled), MariaDB10.4.32, Node24.19.0, Composer2.9.3.
MySQL tests explicitly use disposable synthetic coop_test_* schemas on127.0.0.1:13307. The runner must not be changed to target an application database. Database suites reset their fixtures; do not run the same fixture suite concurrently.
Root Composer dependencies are installed; enable GD/ZIP for Composer using per-command PHP flags where required. Import autoload paths now use root vendor/autoload.php and the former dependency failure is resolved. composer.lock is retained for reproducibility.

## What this establishes
Real MariaDB tests exercise replayed additive migrations, row locks/concurrent duplicate creation, keyed ledger commands, concurrent posting/reversal/numbering, rollback on audit/link faults, member balances, closing/reconciliation, exact held-fund conservation and loan draft/version storage.
Pure loan calculations cover six approved methods, explicit policy validation, independent annuity worked example, rounding and sum conservation.
Endpoint tests use actual copied route/page source with synthetic connection/session wiring and real isolated PDO where stated; they establish those branches, not full deployed HTTP integration.
DOM tests exercise actual JavaScript with minimal synthetic DOM/API transport. The holding timeout test deterministically aborts a stalled request and verifies restored controls, preserved choice and uncertain-save messaging.

## Unverified and incomplete
Supported browser inventory was empty; opening an in-app browser failed with Browser is not available: iab. No real viewport, native keyboard, browser accessibility, slow-network or full authenticated end-to-end acceptance pass is claimed.
The full application has no configured local .env and the recovered checkout still lacks some unchanged assets. No production credentials, migrations, payouts or member notifications were used.
Loan publication, fees, calendar/rate conversions, acceptance/eligibility/exposure/disbursement integration and manual review authorization remain incomplete. A calculator pass does not establish a functioning loan product workflow.
Loan-repayment excess treatment awaits the owner's decision; existing behavior must not be represented as newly approved policy. Full-platform requirements remain tracked in IMPLEMENTATION_AUDIT.md.
Graphify was unavailable. Referenced optional skill browser/checklist scripts were absent; documented source inspection and executable tests were used instead.

## 22 October 2026 combined checkpoint
Command: C:\xampp\php\php.exe work/run-evidence-with-db.php tests/run.php --mysql (outside sandbox). Result: 51 scripts, 492 reported PASS checks, zero failures, zero skipped database scripts. Broad PHP lint: 337 files excluding vendor/work/.git, zero failures. Sandbox-only attempt failed on paths/database access; all failures passed in the outside-sandbox rerun. No real-browser or production acceptance is claimed. See AGENTS.md for remaining work.
