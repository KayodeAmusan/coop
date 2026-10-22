# Architecture contracts and implementation dependencies

21 October 2026, Africa/Lagos. Source-reviewed current local branch; no deployment claim. Applied senior-architect skill. Graphify unavailable; targeted source inspection is the fallback. Baseline classifications remain in IMPLEMENTATION_AUDIT.md.

## Architecture and reuse

Retain native PHP/PDO/MySQL, server-rendered administration, JWT member APIs and shared domain transitions. No new framework, wallet or second ledger. Reuse includes/header.php, footer.php and sidebar.php; JWTHandler and member_identity_helper.php; loan_application_helper.php and loan_consent_helper.php; AccountingEngine, MemberAccountManager, AccountBalanceCalculator, PeriodClosingProcessor, BankReconciliationService; payroll_helper.php; NotificationService and EmailQueueManager; existing import response/parsing infrastructure. README references do not supply absent Flutter source.

CoopID identifies membership; StaffID is payroll identity and may recur across inactive/rejoined records. MemberAccountManager legacy memberid denotes StaffID. Explicit mapping and historical reconciliation are required before adding products. Never credit by name.

Current admin_access_helper uses explicit normalized admin/administrator/superadmin/superadministrator allowlist. This fixes substring matching; granular finance/document/election/auditor permissions and self-approval restrictions remain missing.

## Shared API and financial contract

Browser, API, import and job adapters authenticate actor, authorize capability/object, validate typed input, lock affected rows, apply one permitted transition, write evidence/audit, commit, then notify. Derive ownership from JWT. Persist borrower/subject separately from staff actor. Downloads, exports and reads require object access too. Session mutations require CSRF protection and unsafe HTTP methods.

Use decimal strings at JSON/PDO boundaries and integer kobo internally through money_helper.php; legacy numeric outputs are presentation compatibility only. Reject invalid scale/overflow. Published policy supplies rates/rounding; do not invent them. Current positive-only loan parser duplicates money parsing; consolidate only with preserving tests.

Target authority: balanced committed journal with immutable source evidence and matching member subledger effects in one transaction, reconciled to control accounts. Correct posted history through reversals/current-period corrections. Legacy financial tables are source/reconciliation surfaces until reconciled.

Business retry safety needs durable unique command identity plus payload hash linking source, journal and member effects atomically. Same key/same payload returns existing result; changed payload conflicts. postEntry idempotence for one journal ID does not deduplicate createJournalEntry or recordMemberTransaction. All callers require integration checks.

Proposed capability vocabulary (not implemented roles): member.self, members.manage, loans.review, loans.disburse, finance.post/reconcile, documents.manage, elections.manage, reports.audit. Explicit role mapping, unknown-role denial and configured submitter/reviewer separation are required.

## Critical financial bypasses

loan/classes/LoanManager.php::insertLoan writes tbl_loans using float/int conversion independently of authenticated loan_requests. loan/postAccount_.php deletes/recreates tbl_loanapproval from excel via GET batch parameter. bank_statement_processor.php name/fuzzy matches and directly upserts monthly contributions. api/upload_json_data.php separately changes tbl_loansavings. These remain broken boundaries until enforced source-linked gateways replace unsafe paths.

Approval is distinct from actual payout. Disbursement needs unique persisted linkage among application, legacy loan where applicable, settlement evidence and journal/source event. Lock application and guarantor capacities in consistent order; revalidate membership, approved terms, authenticated consent, exposure and payout details, then settle exactly once. Unknown historic links remain explicit exceptions. Never simulate payout-provider success.

## Product and consent lifecycle

Current evidence fields: guarantor_requests.responded_by_coop_id, consent_terms_hash and append-only loan_guarantor_response_history (terms_snapshot, actor, response, time). Current hash covers borrower, requested amount and monthly repayment only; two guarantors preserve legacy behavior. Expiry/revocation/exposure and full configurable terms are not delivered. Never authenticate legacy responses by backfill.

Future additive model freezes product/version, accepted snapshot/hash/time, amount/currency, duration/frequency, charges/fees, schedule version and guarantor policy per application/enrolment. Published versions are immutable; material amendments create a new version and invalidate affected consent.

Commitments require guarantor/application/version, guaranteed amount/exposure basis, presented snapshot, authenticated decision/time, expiry/revocation/release history. Invitation, consent, approval, disbursement and release are separate transitions. Reserve capacity under guarantor locks; count/slot tests do not establish exposure protection.

## Other domain contracts

Requests/refunds/exit: distinguish posted/reserved/available. Reserve at policy-defined stage under member/product locks; release exactly once on rejection/cancellation; consume with one settled posting. Refunds lock verified excess source/remaining amount. Exit reconciles debt, guarantees, shares, requests/restrictions before verified settlement and retained history.

Shares/bonuses: reserve holdings, eligible buyer acceptance, verified payment, atomic conserved transfer. Bonuses freeze inputs, policy/calculation version, exact allocations/remainder; batch/member unique posting keys prevent duplicates. No executable administrator formulas.

Documents/imports: opaque identifiers/version metadata, private storage, authorized streaming, server-side type/size validation, configured malware quarantine, sensitive-cache/log suppression, checksums. Manifests map actual files to stable member IDs. Stage import file hash/schema/row key/normalized IDs/errors/control totals, dry-run before posting, declare atomic/partial policy per type; durable jobs and source uniqueness support retry. Reject ambiguous identity.

Ballots: freeze rules/eligibility at opening; atomically consume entitlement separately from anonymous choice. No member-choice foreign key, shared correlation token, identifiable choice response/log/report. Split tables alone do not prevent timing/backup identification; review officer access/logging. Immutable ballots, separate publication authorization, configured quorum/ties/reruns. Attendance is not elections.

## Whole-brief feature matrix

| Section | Current status and evidence |
| --- | --- |
| 5 Membership/permissions | Partial: employee/profile/identity code; category/lifecycle, granular roles, bulk onboarding and object-access audit incomplete. |
| 6 Savings | Missing: contribution accounts are not versioned products/enrolment/goals/change requests/reservations. |
| 7 Deposits/deductions | Partial, broken legacy allocation: exact payroll helper/tests; name-based bank matching, claim verification/discrepancies/source deduplication remain. |
| 8 Loans | Partial, broken legacy bypass: JWT ownership/consent/history/locks/tests; full terms/exposure/expiry/revocation/disbursement/repayment/closure missing. |
| 9 Manual/third-party | Partial: manual approved creation blocked/shared creation; third-party meaning and legacy loan gateway unresolved. |
| 10 Requests/refunds/exit | Missing: no complete reservation-to-settlement flow. |
| 11 Shares | Partial: account concept exists; acceptance/reservation/settled conserved transfer absent. |
| 12 Bonuses | Partial: dividend calculators exist; frozen approval/remainder/idempotent cash/share batch incomplete. |
| 13 Documents/imports | Partial, broken legacy paths: helpers/uploads exist; private storage/download, dependencies and durable stable-ID staging remain. |
| 14 Elections | Missing: attendance is not immutable eligible secret ballots/publication. |
| 15 Notices/ads | Missing: complete scheduling/audience/expiry/moderation absent. |
| 16 Accounting | Partial, legacy paths broken: exact engine/subledger/closing/reconciliation repairs/tests; all callers/business idempotency/reconciliation unfinished. |
| 17 Reports/projections | Partial: existing statements/reports; reconciled scopes, new domain reports, authorized exports/projections unverified. |
| 18 UX/accessibility | Unverified: shared redesign in progress; complete authenticated mobile/member/keyboard journeys need browser evidence. |
| 19 Performance | Partial/unverified: email queue foundation; measured mobile/slow-network budgets, bounded queries/durable exports incomplete. |
| 20 Security/integrations | Partial: JWT/loan/admin-allowlist improvements; capability/CSRF/object access/private documents/legacy writes remain. Live IPPIS/payout not proved. |

## Delivery dependency order

1. Full reproducible checkout/dependencies and combined PHP/JS/disposable MySQL migration/race acceptance; validate ledger callers.
2. Capability/CSRF/object-access and legacy financial gateways; explicit identity mapping, atomic unique business-command linkage and reconciliation exceptions.
3. Membership category/lifecycle/private documents, validated onboarding and actual admin/member interfaces.
4. Versioned products/accepted terms, future schedules/contribution changes, goals and distinct balances.
5. Loan amendments/commitment capacity/lifecycle, separate consent-bound disbursement, repayments/closure and assisted parity.
6. Private deposit claims, stable-ID deductions/returns, discrepancy workbench and verified idempotent posting.
7. Reserved requests/refunds/exit; conserved shares; frozen exact bonus batches.
8. Private immutable elections; official notices and separately moderated lightweight ads.
9. Reconciled reports/exports, durable jobs, measured mobile/keyboard/slow-network acceptance.

Each slice requires migration replay, domain/API permission tests, meaningful races/retries, interfaces and audit evidence. Tables or mock screens do not establish completion.

## Cooperative policy input register

Required inputs: cash/IPPIS identity/category; role/capability/self-approval mapping; savings limits/frequency/fees/returns/maturity/amendments; loan rates/method/fee timing/refundability/limits/stages/schedules; guarantor count/eligibility/exposure/expiry/revocation/release/material changes; historical grandfathering/re-consent; third-party borrower/institution meaning; payout verification/withdrawal reservation/refund/exit offsets/fees; share valuation/minimum/restrictions/settlement; bonus population/basis/reversals/rounding/cash-vs-shares; document access/retention/malware provider; election eligibility/secrecy/windows/quorum/ties/reruns/results; provider credentials, notification policy and operational SLO/RPO/RTO.

Until approved: draft configurations only for undefined products; no invented rates/constitution/integration; assisted member applications only for defined eligibility; preserve history without fabricated consent/openings; approval never means paid; ambiguous IDs quarantine; private documents never become public; no production notifications/payouts.

## Migration/reconciliation order

Inventory actual staging schema, backup and restore first. Loan setup variants are alternatives, not a cumulative chain. add_authenticated_guarantor_consent.sql precedes consent-aware endpoints; 20261021_ledger_sequences.sql precedes sequence-backed journal creation. Root is preparing reference_id VARCHAR(255) migration for alphanumeric references: verify final migration filename/order in integration record. Disposable coop_test_* evidence does not prove unknown production data compatibility.

Establish identity/source mappings and reconcile member/product/period/control totals before product versions/private storage, then commitments/reservations/disbursement/new finances. Elections/notices can proceed independently once identity/capabilities/private attachments exist. Preserve source IDs/actors/dates and explicit differences. No fabricated openings, authenticated legacy consent or overwritten postings. Stage dry-run totals, replay/retry evidence and restore rehearsal before separately authorized production cutover.

## Owner revision: guarantor document approval gate
On 21 October 2026 the owner explicitly revised the original direct-authentication requirement: uploaded signed guarantor approval proof is required for both offline and online cases. The borrower, guarantor or authorized staff may upload it. Cooperative staff must review and accept or reject it before loan approval. Preserve the named guarantor, uploader and reviewer as distinct identities; an upload must never fabricate a guarantor-authenticated response. Current terms binding, rejected/quarantined/stale evidence and latest-version enforcement remain mandatory. Owner confirmed that a different staff member must review any staff-uploaded document. Implementation and tests are in progress; prior direct-consent-only tests are a historical checkpoint, not acceptance of this new workflow.
