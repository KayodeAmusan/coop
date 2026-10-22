# Cooperative staff RBAC contract

Status: isolated backend foundation implemented and tested; deployment bootstrap, role editor UI and application-wide capability migration remain incomplete. Each cooperative uses a separate deployment and database.

## Identity boundary

Cooperative staff authenticate through existing `login.php` using active `tblusers.user_id`, stored as `SESS_MEMBER_ID`. Existing `AdminType` strings and cached session roles are legacy compatibility labels. They must never identify an Auracle platform administrator. Platform login, credentials, cookie and deployment bootstrap are independent and owned by the platform authority service. Cooperative user/role APIs must not write platform identity or platform grants.

Member JWT `user_id`/CoopID and staff user IDs are different namespaces. Matching numeric IDs does not confer staff membership or privileges.

## Proposed cooperative authorization

The `CooperativeAccessService` foundation uses a server-owned permission catalog, configurable named cooperative roles, role-permission assignments and staff-role membership. Every new capability check reloads active staff status and assignments from the database, denies missing/unknown permission or inactive/unassigned staff, and ignores client-supplied actor or session role grants. No automatic Finance, reviewer or other business role grants are inferred.

Current ungranted action catalog: `access.roles.manage`, `access.users.read`, `access.users.manage`, `loan.products.manage`, `loans.approve`, `loans.evidence.review`, `loans.evidence.upload`, `accounting.journals.post`, `accounting.journals.reverse`, `accounting.periods.close`, `accounting.bank.reconcile`, `payroll.process`, `payroll.configure`, `members.manage`, `reports.read`. Catalog registration does not grant these permissions. Each migrated endpoint must call the corresponding capability server-side; hiding a button is insufficient.

Initial cooperative role-editor bootstrap requires an explicit deployment-operator CLI action naming an active existing staff ID; no assumed account, password, role-label conversion or HTTP bootstrap. This proposal awaits orchestration confirmation. Platform bootstrap never supplies cooperative role-editor rights implicitly.

Role administration commands implement `create_role`, `set_permissions`, `set_member_roles`, with strict field validation and actor/action/idempotency-key scope. Same validated payload returns the original stored result, changed payload conflicts. Mutation, receipt and audit commit atomically with savepoint ownership; failure must not commit a caller transaction. Concurrent authorization changes must serialize and must not remove the last active cooperative role editor. No direct writes to `tblusers.AdminType` grant new capabilities.

## Compatibility boundary and current containment

Existing exact-Admin checks are partial RBAC and remain temporarily on their current protected endpoints while migration is explicit. They are not fallback authority for the new role editor or any Auracle capability. Current `findAdminAccessError` callers: close_period, configure_payroll_holding, create_bank_reconciliation, create_journal_entry, employee, get_book_balance, loan_product_drafts, masterReport, reverse_transaction, upload_json_data, users. This list records compatibility scope, not proof that every application endpoint is protected.

`api/users.php` previously exposed unauthenticated `SELECT *` (including credential columns) and arbitrary CRUD/role assignment. It now requires a numeric authenticated exact-Admin session before opening the database, returns only explicit noncredential user fields with bounded pagination and no-store, and rejects legacy mutations before opening the database. User writes remain unavailable until the audited authorized workflow is implemented. Tests: `tests/users_boundary_test.php` proves guard ordering using a database tripwire and checks the safe projection.

## API and verified foundation

`api/cooperative_access.php` accepts GET with page (1..10000) and limit (1..100), returning `{success:true,data:{permissions,roles,grants,staff,memberships,page,limit,total_staff}}`. The staff projection is `user_id,CompleteName,Username,Status`; memberships cover the returned staff page. POST requires same-origin and JSON `{action,payload,idempotency_key}`. Payloads:

- `create_role`: `{role_code,display_name}`; code lowercase ASCII letter then letters/digits/underscore/dot/hyphen, max64; display name max150 bytes.
- `set_permissions`: `{role_id,permissions:[catalog codes]}`; replaces the complete role grant set, canonical sorting/duplicate removal for retry identity.
- `set_member_roles`: `{staff_user_id,role_ids:[IDs]}`; replaces the complete active staff membership set, max100 canonical IDs.

Responses are `{success:true,data:{...result,replayed:false|true}}`; validation400, forbidden403, missing404, changed retry409, oversize413, generic private500. Numeric session staff identity is necessary; live `access.roles.manage` is authoritative. Legacy role labels have no effect on this API. Stable keys are actor+operation scoped, required for every mutation. Permission checks use current shared locking reads so a stale repeatable-read snapshot cannot retain revoked grants. Mutating administration holds the control row before checking authority. Services migrating financial actions must check their capability inside their transaction so revocation cannot race the protected write.

Migration `20261021_cooperative_rbac.sql` creates no grants and maps no existing roles. The down migration preserves configured roles, bootstrap and command history. Test-only setup creates synthetic grants directly, which is not production bootstrap.

Verification: `COOP_ACCESS_MYSQL_TEST=1 php work/run-evidence-with-db.php tests/cooperative_access_mysql_test.php` (loopback13307 fixed `coop_test_access` only), 13 tests pass: empty deny, live/inactive grants, canonical replay/conflict, unknown/injected fields, last-editor protection, configurable assignments, unauthorized edits, audit and caller rollback, concurrent identical command, repeatable-read revocation, actual API role-label independence, migration replay and down evidence retention. `php tests/users_boundary_test.php` passes two focused guard/projection tests.

Remaining work includes confirmed bootstrap, role editor UI, user lifecycle command implementation, migrating every protected business action to explicit capabilities, broader protected-operation race tests, and a complete route inventory. This contract must not be presented as completed granular RBAC.
