# Bank reconciliation command identity

Owner requirement: idempotency is mandatory across the system. This change covers the named bank-reconciliation command only; it does not close unrelated payroll, import, CRUD or notification gaps in the system audit.

## Durable command

New `BankReconciliationService::createReconciliation` calls and `api/create_bank_reconciliation.php` submissions require a stable `idempotency_key`. Keys are scoped to the trusted staff actor (`reconciled_by`) and this operation's table. Historical reconciliations keep NULL keys and are readable; they are never guessed into a command identity or reposted.

The fingerprint covers validated period and bank-account IDs, explicit reconciliation date, exact canonical statement/book balances, outstanding deposits/withdrawals, bank charges/interest, trusted actor and normalized notes. Every field must be present; unknown/unkeyed writes are rejected. Adjustments cannot be negative. An identical successful retry returns the originally stored reconciliation result with `replayed: true`. A valid changed payload with the same actor/key returns HTTP 409. Existing successful retries are read before current-book or period-closed checks, so a response lost after posting cannot generate another set of charges or interest.

`result_json` stores original exact variance/adjusted balances, reconciliation ID and linked charges/interest journal IDs. It is not recalculated from mutable current bank balances. Both adjustment journals have deterministic internal command keys and use the explicit reconciliation date. Reconciliation creation, both journal postings, the protected creation audit and original result commit together. Failures and caller-owned transactions preserve existing rollback/savepoint behavior.

Apply `database/migrations/20261021_journal_idempotency.sql` and `20261021_bank_reconciliation_idempotency.sql` before enabling the new writer. The bank migration is repeatable and additive. Its down counterpart removes only empty receipt fields; it preserves every nonnull command/hash/result record and drops the composite index before removing any column. Roll application code back first.

## API and actual form

The API checks administrator permission, trusted numeric staff identity, POST and same-origin rules before opening the database. It accepts only the documented form fields and gets the actor from the session, never from submitted `reconciled_by`. It returns precise decimal strings and stored journal links. Validation errors use HTTP 400, changed command payload uses 409, and internal failures return a generic 500 message.

`coop_bank_reconciliation.php` creates a cryptographic initial key. Before posting, it saves the exact pending form payload and key in tab-local `sessionStorage`, scoped to the trusted staff ID. A lost response, 30-second timeout or page reload retains/restores that original payload; restoration never automatically submits. A pending reconciliation is not silently refreshed to a different current book balance. Changed pending details require explicit new intent; the existing key remains associated with the original details.

A server-confirmed result clears pending recovery state and disables saving that completed intent until a new reconciliation or reload. The explicit new-reconciliation action confirms uncertain history, clears recovery state and obtains another cryptographic reference. Missing recovery storage or secure-reference support fails closed. No client actor is posted. The book field uses `balance_decimal` directly; client display arithmetic uses integer kobo and is labelled as a preview pending server confirmation.

## Focused verification

`tests/accounting_workflows_database_test.php` covers exact original/replayed results, stored journal links, closed-period and changed-book retries, canonical decimal equivalence, conflicts for every financial/date/account/notes field, actor scope, missing/unknown inputs, nullable legacy preservation, concurrent same-key creation, audit/result persistence faults, second-adjustment failure and caller rollback. The concurrent case holds the period row until three workers reach the same lock after missing their initial key lookup.

`tests/bank_reconciliation_ui_test.cjs` executes the actual form script and checks lost responses, exact retries, changed-payload blocking, reload recovery, immutable pending book values, explicit new intent, timeout, storage/crypto failures and precise book DTO use. `tests/financial_route_boundary_test.php` exercises route permission/origin/method before database access.

No production data, bank integration or real payment was accessed. Browser DOM/network behavior beyond the focused script harness and production migration rollout still require their normal environment validation.
