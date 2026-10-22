# Member journeys and UI acceptance contract

Date: 21 October 2026. Scope: implementation guidance from the original cooperative platform brief and inspected repository source. This document does not claim implemented screens, successful runtime requests, interviews, usability research, or browser verification.

## Evidence and assumptions

The original brief prioritises financial correctness/privacy, complete workflows, simple member use, affordable phones, unreliable connections, and reuse. First-time use, shared devices, and slow networks are brief-derived design hypotheses, not observed findings. Validate them with cooperative members before declaring usability proven. The repository audit reports no maintainable Flutter/member web client; JSON endpoints alone do not establish a usable portal. Existing administrative PHP pages are the immediate integration surface.

Read skills: ux-researcher-designer/SKILL.md and ux-writing/SKILL.md. The latter references content/voice-tone.md, which is absent from its installed directory. Its separate 10-item checklist could not be executed; the explicit rules available in SKILL.md were applied to the copy below. No Graphify or user-research execution is claimed.

Inspected evidence: header.php; sidebar.php; IMPLEMENTATION_AUDIT.md; auth_api/api/auth/get_wallet_data.php; auth_api/api/loans/request.php, tracking.php, guarantor-request.php; auth_api/api/transactions/transaction-summary.php; auth_api/api/payroll/download.php. Other paths below were inventoried; their existence is not evidence of working or secure behavior. No AGENTS.md was found in the checkout inventory.

## Navigation and reuse

Proposed member navigation: Home, Savings, Loans, Documents, More. More contains Profile, Shares, Statements, Requests and Voting only when the relevant backend and access checks are ready. Surface guarantor requests as an explicit task under Loans and on Home. Keep official notices secondary and advertisements below financial tasks. Home prioritises outstanding tasks, a verified account overview, and recent activity. Primary action is View savings when no task needs attention; when an authenticated guarantee request is pending, Review guarantee request is the primary task. Avoid multiple visually equal primary buttons.

Keep member and staff navigation separate. Reuse existing session/JWT identity, PHP layouts, notification paths, amount/date/reference formatting, tables, filters, status badges, dialogs and server validation. Extend a shared pattern before adding a component. Verify every destination exists: sidebar.php includes processLoan.php and enquiry.php, neither present in the initial root inventory. Do not implement a decorative menu that routes to absent workflows. UI hiding is not authorization.

## Journey contracts

| Journey and success | Verified foundation | Proposed flow and explicit gaps |
| --- | --- | --- |
| Member home: understand the account and next task | get_wallet_data.php aggregates savings, shares and unpaid loans for JWT user_id; tracking.php binds requested member to authenticated identity | Authenticate, load scoped overview, show source/update date, open one relevant task. No invented balances, notices, available funds, approval notifications or chart history. Before integration remove credential logging from wallet route and verify reconciled totals. Failed balance lookup means unavailable, never zero. |
| Check savings: understand posted and usable funds | get_wallet_data.php savings_balance; transaction-summary.php period-filtered history; get_periods.php inventoried | Choose savings product when products exist, see posted balance, reserved funds, available to withdraw and dated entries; inspect transaction reference. Current aggregate is not a product model and cannot supply reservations/availability. Until backend provides those facts label it Savings balance with its scope; omit withdrawal call to action. Product terms/version and goal projections remain dependencies. |
| Loan application: submit a traceable request | request.php POST creates member-bound request with amount/period and payslip-related inputs; upload_payslip.php inventoried; tracking.php GET | Explain product eligibility and configured costs; group form as Amount and repayment, Supporting documents, Guarantors; then Review application and Submit loan application. Review must show fees/timing/refundability, total repayment, schedule and terms version supplied by server. Existing fixed calculation is not evidence of configurable loan products. Do not offer arbitrary durations/rates unsupported by backend. Show reference and next required action only after confirmed submission. |
| Guarantor consent: make an informed independent decision | guarantor-request.php POST invitations, GET requests, PUT response; authenticated ownership/locking inspected | Guarantor signs in independently, opens request, sees borrower, requested/guaranteed amounts, repayment terms/version, obligations and existing/resulting exposure, then reviews and chooses Accept guarantee or Decline guarantee. Acceptance is not loan approval or payment. Require fresh displayed terms after material changes. Missing exposure/expiry/withdrawal policy blocks claims that those safeguards exist. Staff may assist with navigation, never authenticate or consent as guarantor. |
| Withdrawal, over-deduction refund, exit: track through settlement | No complete workflow established by audit/source inventory | Separate request types. Identify savings product or verified excess deduction; enter amount/reason/supporting evidence; show eligibility, charges, reservation, payout details and review; submit; show reference and decision history. Approved means approved for processing; Paid/Settled requires actual settlement evidence. Exit requires a breakdown of savings, shares, loans, guarantees and charges. No live submit action until authorization, reservation/concurrency, refundable-source and settlement services exist. |
| Documents and payslips: access own permitted files | payroll periods/payslip/download routes and loan payslip upload inventoried | Select type/period, see name/type/size/date and status, explicitly Preview or Download. Upload labels state backend-supported types/limits before file selection; show progress, validation and retry. A file selected or uploaded is not reviewed/accepted. Backend must supply private authorized storage, owner-scoped listing and download; do not link a public upload path or cache sensitive previews. Bulk manifests are staff tools, not member upload requirements. |
| Share sale/transfer: complete an agreed, settled transfer | get_wallet_data.php shares_balance; no complete transfer workflow established | Show verified share quantity and restricted/reserved/available quantities separately; choose buyer by stable member identity, quantity and policy-approved price; review seller offer; buyer independently accepts; staff review/payment verification if required; completion reference for each party. Never equate shares_balance amount with share quantity. No wallet/gateway invention or ownership movement before verified settlement. |
| Statement: understand and download an accurate period account | transaction-summary.php with period filters; get_periods.php; coop_member_statement.php staff page | Select From period and To period, View statement; show opening balance, dated referenced postings and closing balance only from reconciled data; explicit Download statement after authorized export exists. Keep an HTML reading path alongside download. Member scoping of legacy transaction/export routes must be verified before exposing them. Staff report access must not become a member endpoint implicitly. |
| Voting: cast one eligible ballot and confirm participation | Event/attendance routes exist; no election service established | Show actual election window/eligibility/rules, choose candidate/resolution options, review selections, use Cast ballot confirmation, receive participation receipt. Secret-ballot receipt must not reveal choices or allow ordinary reports/logs to join member to choice. Attendance/check-in is not voting. No selectable ballot until frozen rules, eligibility snapshot, atomic entitlement, secrecy and result-publication controls exist. |

## Forms, states and financial truthfulness

Use persistent visible labels and helper text, one topic per short group, clear required/optional marking and a named primary action. Preserve safe nonsensitive fields on validation failures; server rules remain authoritative. Money fields need numeric keyboard hints and explicit naira units while accepting the backend's exact decimal format. Do not use client float estimates as authoritative repayments. Display full member identity needed to confirm a counterparty without leaking broad member records.

| State | Behavior and copy |
| --- | --- |
| Loading | Show a named loading state such as Loading loan requests. Never replace a pending balance with a zero or disable the whole page unnecessarily. |
| Empty | No loan applications yet. View loan options to learn what is available. Render View loan options only when it has a working destination. |
| Invalid field | Enter an amount greater than zero. Focus an error summary linked to the field; retain entered safe values and associate error text using aria-describedby. |
| Session ended | Your session has ended. Sign in again to continue. Do not silently submit after reauthentication; reopen a fresh review. |
| Connection lost before confirmed send | We could not confirm whether your request was received. Check your request history before submitting again. Do not announce failure or auto-retry a financial mutation without server idempotency/status lookup. |
| Confirmed submission | Loan application submitted. Reference: [server reference]. Track the application for the next step. Show success only from authoritative response/status. |
| Approved request | Your request is approved and awaiting payment processing. Use only when backend supplies that state; never display Paid from an approval flag. |
| Changed terms | The loan terms have changed. Review the updated terms before accepting this guarantee. Clear obsolete consent state. |
| Rejected request | Show the server-provided safe decision reason, reference and available next action. Do not promise resubmission, appeal or cancellation where unsupported. |

Financial actions require a distinct review page or equivalent accessible review panel showing material terms, beneficiary and amount. Confirmation buttons name action/object: Submit withdrawal request, Accept guarantee, Cast ballot. Offer Cancel before commitment. For irreversible/high-stakes actions the invoked ux-writing skill requests a type-to-confirm step; use a short action phrase and accessible explanation without collecting secrets. Preserve the review and a way to correct details; validate the resulting friction with actual first-time users. Never claim a browser-side confirmation satisfies server security or legal policy.

## Shared devices, privacy and interruptions

Keep Sign out visible; do not expose a switch-user control that bypasses authentication. On sign out/session expiry discard sensitive rendered content and require authenticated navigation again; verify cache-control/back-button behavior server-side. Use password-manager-compatible fields without placing credentials/tokens in URLs or logs. Do not automatically persist financial forms, documents, balances, ballot choices or tokens to local storage/offline caches. Optional hiding of balance values must be accessible and cannot replace ownership checks. Download action warns briefly that the file will be saved on this device when appropriate.

Use explicit Load more/page controls for bounded lists and preserve filters in a safe navigation state. Defer charts, large images and document viewers until needed; core tasks must remain usable while these fail. Durable uploads/jobs, resumable drafts, offline submission and notifications are backend capabilities to implement and verify, not promises in UI copy. Never queue a payout or ballot automatically from an offline page.

## Acceptance and verification plan

These are proposed acceptance checks, not measured results. Test both member and staff permissions with test accounts; no production notices, payouts or votes.

| Width/context | Required evidence |
| --- | --- |
| 360 px | Single-column forms; no page-level horizontal scrolling; labels, naira amounts/references and errors wrap; primary action fits; menu, dialogs and file controls work with touch. Tables may use a clearly labelled contained horizontal region with all data accessible. |
| 390 px | Repeat longest names/amounts/references, keyboard-open inputs and validation; no fixed footer obscures field, focus or confirmation action. |
| 768 px | Navigation expands predictably without duplicate tab stops; review summaries remain in reading order; portrait/landscape work; content does not rely on hover. |
| 1280 px | Bounded reading width, useful table columns and a clear primary action; keyboard traverses navigation, filters, dialogs and confirmation in logical order. |
| All widths | Minimum 44 by 44 CSS-pixel primary touch targets; visible focus; semantic headings/landmarks; labelled icon buttons; status text independent of color; adequate text/nontext contrast; 200% zoom and reflow checks; screen-reader announcements for meaningful asynchronous errors/success without repeated chatter. Treat these as acceptance targets, not an accessibility certification. |
| Slow/interrupted network | Throttle a development browser, interrupt list/load/upload/submission separately, recover/reload, and verify no duplicate request or misleading success. Server idempotency/concurrency tests are separate required evidence. |
| Privacy and authorization | Sign out and use Back; attempt another member's identifiers/download reference; confirm no account/ballot disclosure, token logging, stale sensitive cache or unauthorized staff action. |

Future research plan: recruit at least 5 cooperative members covering first-time smartphone use and shared-device contexts, with consent and test data. Ask them to find a savings entry, understand guarantee exposure, distinguish approval from payment, recover after a lost connection, and sign out. Record completion/errors/interpretation and accessibility barriers; do not insert invented findings into the backlog. Prioritise any inability to understand financial commitment or any privacy disclosure above cosmetic preference.

## Integration order and open dependencies

1. Repair and verify identity, privacy and financial service boundaries; get_wallet_data.php currently logs token/user data and enables display_errors. Reconcile source balances and secure downloads.
2. Improve shared staff navigation and real loan review/tracking screens first; preserve existing domain contracts and server-enforced consent. UI owner receives this contract; no source files were changed by this UX task.
3. Implement complete member vertical slices when a maintainable member surface and truthful API contracts exist. Add products/reservations/settlement before withdrawal, share or available-funds controls.
4. Add elections only with constitution/policy and secret-ballot services. Validate document limits, support contact, language needs, loan product rules, guarantee exposure/expiry/revocation, share valuation and refund/exit policy with cooperative owners; no invented policy values.
5. Save browser/authorization/network verification evidence against this table and update feature status only after end-to-end checks. This document alone does not close a workflow.
