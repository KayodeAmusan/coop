# CODEX IMPLEMENTATION PROMPT: COOPERATIVE SOCIETY PLATFORM

## 1. Mission and repository

Act as the engineering team responsible for completing a Nigerian cooperative society management platform using the existing `coop_admin` GitHub repository as the foundation.

**Repository URL:** 
**Working branch:** create an appropriately named feature branch from the repository’s intended base branch.

Evaluate the existing application, identify what already works, and implement every missing or incomplete requirement below. Deliver functioning member and administrator experiences, backend workflows, migrations, permissions, reports, and verification.

Priorities, in order: financial correctness and privacy; complete workflows; simple member experience; performance on affordable phones and unreliable networks; maintainability through reuse.

Preserve and improve the existing stack. Do not assume a framework, rebuild the application from scratch, or introduce another architecture merely because you prefer it.

## 2. Required skills and coordination

Discover the skills available in this Codex environment, resolve their actual registered names, and read the relevant SKILL.md instructions before using them.

- **Use Graphify** to understand the repository through its documented analysis workflow. Map modules, dependencies, routes, services, models, shared components, financial posting paths, permissions, and tests. Verify graph findings against source code.
- **Use Ponytail as the operations manager**, within its documented capabilities. Have it coordinate the backlog, dependencies, assignments, progress, integration, risks, and completion checks.
- **Use my backend engineer skill** for domain modelling, business rules, APIs, migrations, transactions, background jobs, and backend verification.
- **Use my UI/UX skill** for navigation, member journeys, responsive layouts, accessibility, shared components, and usability on slow networks.
- Apply other available skills when they materially help, including database, frontend, security, testing, and performance skills.

Parallelize independent work where supported. Give each worker clear ownership and shared API contracts. Coordinate changes to shared files, schemas, and migrations.

Do not invent skill commands or claim that an unavailable skill ran. If a named skill is missing, report the exact missing dependency, continue work that can proceed independently, and request its location only where necessary. Use documented fallbacks transparently.

## 3. Repository audit before implementation

Resolve the exact repository from the supplied URL or a verified existing remote. If the URL remains a placeholder and the repository cannot be identified uniquely, ask for the URL rather than guessing.

Inspect repository instructions, documentation, Git status, branches, manifests, lockfiles, environment examples, migrations, tests, and deployment configuration. Preserve uncommitted user work. Clone if needed; otherwise fetch and update safely without overwriting work or using destructive resets.

Establish the current build and test baseline. Identify existing failures separately from new regressions. Use development or test data.

Use Graphify and targeted source inspection to produce:

1. A concise architecture map and inventory of reusable functions, services, components, and integrations.
2. A feature matrix covering every requirement in this prompt, classified as **implemented, partial, missing, broken, or unverified**.
3. Evidence for each classification: relevant files, routes, services, models, and tests. A menu entry or database table alone does not establish a working feature.
4. A dependency-ordered implementation plan and a short register of unresolved business policies.
5. A migration and reconciliation strategy that preserves existing members, balances, loans, shares, documents, and history.

Save these in the repository’s existing documentation structure. Keep them useful and current. **After the audit, continue implementation without waiting for routine plan approval.**

## 4. Mandatory code reuse

Search the graph and source before adding a function, service, component, hook, utility, endpoint, or abstraction.

First call a suitable existing implementation. If necessary, extend it safely. **Create a new implementation only when no usable existing implementation can meet the requirement safely; record the reason in the relevant change summary.**

Reuse the established authentication, authorization, financial posting, validation, notifications, storage, exports, and error-handling paths. Avoid parallel balance systems or duplicated business rules.

Build screens from shared components, including layouts, navigation, forms, inputs, tables, pagination, filters, confirmation dialogs, status badges, uploads, notifications, and loading, empty, and error states. Reuse formatting for naira, dates, amounts, and transaction references.

Keep financial and eligibility rules on the backend. Member screens, administrative actions, imports, and background jobs must call the same domain services. Avoid speculative frameworks and giant generic components.

## 5. Membership, onboarding, and permissions

Support two membership categories: **cash/self-paying members** and **IPPIS/payroll-deduction members**. Treat “ipis” in the brief as an assumption meaning IPPIS; verify against repository terminology and document any uncertainty.

Keep membership category separate from payment method. A cash member may pay through an approved bank-transfer or other supported channel. Do not assume a live IPPIS API exists.

Implement administrator onboarding, member search, profile management, document review, activation, suspension, and exit tracking. Support individual creation and validated bulk onboarding.

Maintain stable member identifiers, contact information, membership dates and status, approved payment details, and applicable payroll identifiers. Do not match financial records by name alone.

Members must be able to view their profile, payslips, documents, savings, loans, shares, bonuses, contributions, deductions, request history, and statements. Record profile changes and apply review to sensitive fields where policy requires it.

Implement server-enforced roles and permissions for members, administrators, finance, loan reviewers, document management, election officers, and auditors as appropriate to the existing permission model. Prevent cross-member access and inappropriate self-approval. Record both the member concerned and the staff actor for assisted transactions.

## 6. Savings products and contribution changes

Allow administrators to create and manage **general, specialised, and targeted savings products**.

Configure product name, description, contribution frequency, minimum or maximum amounts, duration, eligibility, terms and conditions, applicable fees or returns, maturity rules, withdrawal restrictions, and approval requirements. Make financial values configurable; do not invent the cooperative’s rates or bylaws.

Members must be able to enrol, view balances and contribution history, and understand the rules they accepted. Targeted savings must include a goal amount, target date, progress, and the estimated contribution required to reach the goal.

Version product terms. Preserve the version accepted by existing members and explain how future changes take effect.

Implement contribution-change requests with current amount, proposed amount, product, effective date, member reason, review, acceptance or rejection, reviewer reason, and notifications. An approved change affects future schedules from its effective date; it must not silently rewrite posted history.

Show posted balance, funds reserved for pending transactions, and available-to-withdraw balance distinctly.

## 7. Deposits, deductions, and reconciliation

Allow members and authorized administrators to upload proof of deposit against a member, product, amount, date, and reference.

Treat uploaded proof as an unverified claim. Credit the account only through the approved verification and posting workflow. Prevent duplicate posting of the same deposit.

Support cash-member collections and IPPIS deduction schedules and returns through existing integrations or validated imports. Track expected deductions, actual deductions, payroll periods, allocation across savings and loans, arrears, and differences.

Handle under-deductions, over-deductions, duplicates, unmatched entries, and rejected rows explicitly. Provide a reconciliation workbench and source-file traceability.

Maintain deduction history and contribution schedules. Do not silently allocate an unidentified payment or create a successful payroll response without a real integration.

## 8. Loans and mandatory guarantor consent

Administrators must be able to configure loan types similarly to savings products: description, eligibility, application fee, duration, limits, repayment frequency, interest or service-charge method where applicable, required documents, guarantor requirements, approval stages, and terms.

Define fee timing and refundability through configuration. Show fees, total repayable amount, and the repayment schedule clearly before submission. Version the terms accepted for each application.

Implement application, document submission, guarantor invitation, consent or rejection, review, approval or rejection, disbursement, repayment, outstanding balance, arrears, and closure.

**Guarantor consent is a non-bypassable backend requirement:**

- Each required guarantor must independently authenticate and explicitly accept before the loan can be approved.
- Show the borrower, requested amount, relevant repayment terms, guaranteed amount, obligations, and resulting guarantee exposure before consent.
- Prevent self-guaranteeing and duplicate guarantors. Enforce configured guarantor eligibility, required count, and exposure limits.
- Store consent or rejection with the guarantor, application, material-terms version, terms presented, and timestamp. Apply configured expiry and withdrawal rules.
- Material changes to amount, duration, repayment terms, or guaranteed exposure invalidate affected consent and require fresh consent.
- Enforce these checks in every approval path, including admin screens, direct APIs, imports, bulk actions, and background jobs.
- Recheck valid consent, eligibility, and exposure before disbursement. Protect concurrent applications from overcommitting a guarantor.
- No administrator status edit may fabricate a guarantor’s consent.

Consent does not itself approve or disburse a loan. Record each decision and actual disbursement separately. Release guarantee commitments according to the approved lifecycle and preserve their history.

## 9. Manual and third-party loan applications

Support staff-assisted entry of applications received manually, retaining borrower identity, source, supporting documents, application date, and the staff actor.

Use the same loan calculations, guarantor requirements, approvals, repayment tracking, and audit controls as online applications.

Resolve what “third-party loans” means from existing code or cooperative policy: manually entered member loans, nonmember borrowers, or loans involving an external institution. Do not silently equate these categories. Implement the clearly defined manual-entry workflow while keeping unresolved borrower eligibility or external settlement rules explicit and configurable.

## 10. Withdrawals, refunds, financial requests, and exit

Provide distinct, understandable workflows for:

- General savings withdrawals.
- Specialised and targeted savings withdrawals under their product rules.
- Other financial requests, with administrator-configured request categories and requirements.
- Refund requests for over-deductions.
- Membership exit and final settlement.

Each request needs eligibility checks, amount where relevant, supporting documents, confirmation, a reference, status tracking, reviewer decisions and reasons, notifications, and settlement evidence.

Distinguish submitted, under-review, approved, rejected, cancelled, processing, and paid or settled states where applicable. **Approval must not automatically imply payment.**

Reserve funds when the configured workflow requires it and prevent concurrent requests from withdrawing the same funds. Validate payout details and permitted changes through appropriate authorization.

An over-deduction refund must reference a verified excess deduction, respect the remaining refundable amount, and prevent duplicate refunds.

Exit processing must reconcile loans, guarantor commitments, savings, shares, pending requests, restrictions, and applicable charges. Produce a settlement breakdown, record final payment, close membership appropriately, and retain historical statements and audit records.

## 11. Shares and member-to-member sales

Members must be able to view their shareholdings and initiate sales or transfers to other eligible members.

Implement seller offer or request, buyer identification and acceptance, quantity, permitted price or valuation, any required administrative review, payment verification or supported settlement, completion, and cancellation.

Separate available shares from pledged, restricted, or reserved shares. Reserve shares during an active transaction and prevent concurrent overselling.

Transfer ownership atomically only when settlement and all conditions are satisfied. Maintain both parties’ transaction references, ownership history, and financial postings where applicable. Member transfers must conserve the total issued shares.

Use an existing supported settlement mechanism or a clearly documented, verified external-payment workflow. Do not introduce an imaginary wallet or payment gateway.

## 12. Bonuses and bonus share issues

Allow administrators to create, preview, approve where required, and distribute bonuses from the dashboard.

Distinguish cash distributions from bonus share issues. Support the applicable distribution types through explicit policy configuration and preserve their different accounting treatment.

Make allocation programmable through safe, validated rules and parameters rather than arbitrary executable code. Configure the eligible population, period, contribution basis, thresholds, exclusions, weighting, rounding, and destination account or shareholding.

Supported allocation bases should include proportional eligible contributions and other cooperative-approved rules. Define treatment of reversed contributions, withdrawals, arrears, and membership changes.

Before posting, show a member-level breakdown, calculation basis, total distribution, and treatment of any remainder. Ensure allocations reconcile exactly to the approved amount or share quantity.

Freeze approved inputs and calculation versions. Make posting idempotent, prevent duplicate distributions, and use controlled reversals for corrections. Show each member the amount or shares received and how the allocation was calculated.

## 13. Document management and bulk uploads

Support payslips, membership records, loan documents, guarantor consent evidence, deposit proofs, and other administrator-configured document categories.

Provide secure upload, member association, metadata, categorization, search, version history, preview, download, and access controls. Members may access their own permitted documents; staff access must follow role permissions.

Validate file size and type, use private storage and authorized downloads, and incorporate malware checks appropriate to the existing stack. Avoid exposing sensitive filenames or contents in logs, public URLs, or browser caches.

Support CSV and Excel/XLSX imports for relevant records, including members, contributions, deductions, repayments, shares, and document metadata.

Provide downloadable templates, column mapping, preview or dry run, row validation, duplicate detection, clear error reports, batch history, reconciliation totals, and safe retry behavior. Define all-or-nothing versus partial processing explicitly for each import type.

For bulk payslips and other documents, implement actual file upload plus a spreadsheet manifest that maps files to stable member identifiers and document types. Do not treat a spreadsheet of filenames as the documents themselves.

Use background processing for large batches. Preserve successful processing safely across retries and show progress without requiring the browser to stay open.

## 14. EXCO elections and AGM voting

Provide a member voting portal and an administrative election dashboard for executive committee elections and AGM resolutions.

Administrators must be able to configure positions or resolutions, candidates or nominations as applicable, voter eligibility, opening and closing times, ballot rules, and publication of results.

Enforce the configured vote entitlement atomically. Prevent duplicate voting, ineligible voting, and voting outside the permitted window across every entry point.

For secret ballots, separate participation records from ballot choices so ordinary administrators, standard reports, and application logs cannot associate a named member with a choice. Provide confirmation of participation without exposing the vote.

Support turnout reporting, tallying, controlled result publication, audit evidence, and explicitly configured rules for ties, quorum where applicable, cancellation, and reruns. Do not invent the cooperative’s election constitution.

Freeze the appropriate election rules and eligibility snapshot when voting begins. Prevent untracked changes to ballots or results.

## 15. Notice board and member advertisements

Implement a notice board for cooperative announcements, AGM notices, deadlines, and other member information.

Support drafts, publishing, scheduling, expiry, pinned notices, attachments, and appropriate audience targeting.

Allow members to submit advertisements for their businesses or services. Provide administrative moderation, approval or rejection with reasons, publication dates, expiry, and clear separation from official cooperative announcements.

Keep advertisements lightweight and subordinate to essential financial tasks. Sanitize member content and protect against unsafe attachments or links.

## 16. Income, expenses, accounting, and audit

Provide income and expense tracking with categories, dates, amounts, payment methods, supporting documents, references, responsible staff, approval where required, and reporting.

Integrate all financial features with one authoritative posting system. Reuse an existing ledger if suitable; if absent or unsafe, implement or repair an auditable ledger with balanced accounting entries where applicable.

Use exact decimal or integer-minor-unit arithmetic for money. Define rounding consistently. Do not use binary floating-point calculations for financial balances.

Use database transactions, idempotency keys, unique constraints, and suitable locking or concurrency control for financial operations. A browser retry, repeated import, duplicate webhook, or retried job must not create a duplicate transaction.

Posted financial records must be immutable through normal application operations. Correct errors with authorized reversals or adjustments that retain the original reference and reason.

Do not classify member deposits or loan principal receipts as income, or withdrawals and loan principal disbursements as expenses. Configure account mappings and distinguish principal, fees, interest, share capital, and liabilities.

Use one consistent source for balances, statements, exports, and dashboard totals. Preserve posting and effective dates, source periods, approval actors, and traceability. Reconcile historical data and approved opening balances during migration; never guess unexplained balances.

## 17. Dashboards, statements, reports, and projections

Provide an administrative dashboard and permission-scoped reports across membership categories, savings products, contributions, expected and actual deductions, loans, guarantees, withdrawals, refunds, exits, shares, bonuses, income, expenses, documents, imports, elections, and advertisements.

Support appropriate date, member, product, category, and status filters; search, drill-down, summaries, charts, and exports. Paginate large datasets and generate substantial reports asynchronously.

Members must be able to generate their own statements and reports for selected periods, including savings, contributions, deductions, loans and repayments, shareholdings, bonuses, and request history. Support useful PDF and CSV/Excel exports without exposing other members’ information.

Statements must show an opening balance, dated transactions and references, understandable debits and credits, running balances where applicable, and a closing balance that reconciles to the ledger.

Provide member projections for savings growth from planned contributions, target completion, contribution changes, and loan repayment or affordability scenarios using configured rules.

Show the assumptions, dates, and estimated nature of every projection. Keep projected values separate from actual balances, and do not promise unapproved returns.

## 18. UI/UX for rural Nigerian members

Prioritize first-time users, affordable Android phones, shared devices, limited data, and unreliable connectivity. Use simple English, familiar terms, clear naira amounts, readable text, and explicit next steps. Keep text ready for future localization without assuming an unsupported language requirement.

Use the UI/UX skill to design and implement the main journeys: checking savings, applying for a loan, consenting as guarantor, requesting a withdrawal or refund, viewing documents, selling shares, generating a statement, and voting.

Provide a clear member home screen with balances, pending requests, required actions, and helpful shortcuts. Make administrative navigation reflect staff responsibilities.

Use responsive layouts across phones, tablets, and desktops. Test representative widths including 360, 390, 768, and 1280 pixels. Avoid whole-page horizontal scrolling.

Use generous touch targets, visible labels, accessible contrast, keyboard navigation, sensible focus handling, screen-reader support, and errors that explain how to recover. Do not communicate status by colour alone.

Keep forms short and logically grouped. Explain fees and requirements before submission. Provide a review step for consequential financial actions and clear confirmation with a reference afterward.

Show pending, failed, and successful operations truthfully. Preserve appropriate form progress through interruptions without storing sensitive documents or financial information insecurely on shared devices.

## 19. Lazy loading and performance

Use route-level code splitting where applicable. **Lazy-load nonessential charts, large tables, images, document viewers, export tools, and below-the-fold content.** Keep essential navigation and primary actions immediately usable.

Use server-side pagination, filtering, and sorting. Prevent oversized payloads and N+1 queries. Add evidence-based indexes, query optimization, and carefully invalidated caching where needed.

Compress assets, minimize heavy dependencies, and avoid unnecessary fonts, animation, polling, or automatic document downloads. Use the existing frontend framework’s native loading capabilities.

Run large imports, exports, bonus batches, notifications, and expensive report calculations in reliable background jobs where appropriate. Provide progress, failure details, and safe retries.

Handle intermittent connections with useful timeout messages and resumable work where supported. Never show a financial transaction, consent, or vote as complete without server confirmation. Do not blindly queue such actions offline.

Record baseline and final measurements under mobile CPU and slow-network throttling. Set realistic performance budgets, show what was measured, and document any remaining bottleneck instead of claiming unmeasured speed.

## 20. Security and integration boundaries

Apply backend authorization to every endpoint, object, download, export, and job. Protect sessions, sensitive administrative actions, inputs, and file access. Use the repository’s established security mechanisms and add targeted controls where missing.

Maintain a protected audit trail for financial decisions, consent, profile changes, imports, product-rule changes, bonus runs, and election administration. Do not log credentials, full payslips, unnecessary personal data, or secret ballot choices.

Use existing configured payment, messaging, identity, payroll, and storage integrations. Where credentials or providers are absent, expose a clear unconfigured state and document the required setup. Keep test fixtures isolated from real workflows.

Use in-app notifications and configured communication channels for relevant status changes, guarantor requests, and repayment information. Avoid leaking financial details through notifications.

Prepare development, staging, migration, backup, recovery, and deployment instructions appropriate to the repository. Do not execute live payouts, production data changes, external member notifications, or production deployment as part of development without separate authorization.

## 21. Execution and verification

Implement complete vertical slices: schema and migration, domain rules, permissions, API, member or admin interface, notifications where applicable, and relevant tests.

Sequence work around actual dependencies: shared foundations and permissions; financial posting and reconciliation; membership and documents; savings and loans; requests, shares, and bonuses; elections and communications; reports; performance and integration verification. Adjust this order where the repository provides a better path.

Maintain a concise progress record with completed requirements, evidence, current work, blockers, and next actions. Preserve enough state to resume after context limits. Continue authorized work without repeatedly asking whether to proceed.

Test meaningful outcomes and high-risk failures, including:

- Loan approval and disbursement blocked by missing, rejected, expired, revoked, or outdated consent.
- Consent enforcement through alternate endpoints, imports, and administrative actions.
- Unauthorized access to another member’s account, documents, reports, or requests.
- Repeated financial submissions, duplicate imports, failed-job retries, and concurrent withdrawals.
- Concurrent guarantees and share transfers that could exceed available capacity or holdings.
- Loan calculations, repayment allocation, deductions, refunds, and exit settlement.
- Bonus totals, rounding, eligibility, and protection against duplicate posting.
- Election eligibility, duplicate voting, open and close boundaries, and ballot privacy.
- Statement reconciliation, report accuracy, migration integrity, and existing functionality.
- Real browser journeys on mobile layouts and slow connections, including interruption and recovery.

Use synthetic, clearly labelled demonstration data for both membership categories. Run available build, lint, type, migration, integration, and browser checks as relevant. Report checks that could not run and why. Fix failures caused by your changes.

## 22. Definition of done and handover

A requirement is complete only when its real data flow works with appropriate permissions and business rules, its member or administrator interface is usable, and relevant verification supports it.

Do not mark placeholder screens, mock balances, disconnected buttons, untested migrations, or simulated external success as completed features.

Deliver:

1. The implemented code, migrations, and reproducible setup.
2. The final feature matrix, with completion evidence and any remaining blockers.
3. The repository architecture and reuse summary, including justified new functions or services.
4. A documented configuration checklist for cooperative-specific rates, limits, approval rules, guarantee policies, bonus formulas, election rules, exit rules, and integration credentials.
5. Test results, mobile usability findings, performance measurements, and reconciliation evidence.
6. Concise member and administrator guidance plus deployment, backup, recovery, and migration notes.
7. A clear change summary and reviewable branch, with commits organized according to repository conventions.

Do not claim full completion while a required feature is blocked or unverified. Complete all independent work and identify the precise remaining input or dependency.

**Begin with repository verification, skill discovery, Graphify analysis, and the feature audit. Then carry the implementation through the full requested scope.**
