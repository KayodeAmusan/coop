# Private guarantor evidence

The owner requires a signed approval document for each guarantor in online and offline workflows. The applicant, named guarantor or authorized administrator can upload it. A staff reviewer must accept the latest approval version for the current loan terms after malware scanning; a staff uploader requires a different staff reviewer. Uploader identity is never represented as authenticated guarantor consent. An acknowledgement is optional supporting evidence and cannot replace signed approval.

## Reuse and deployment

The existing loan consent hash, member JWT, admin access/origin helpers, PDO transactions and loan status refresh are reused. The old `upload_payslip.php` cannot safely provide this storage: it places documents under public uploads, trusts supplied MIME/size information too broadly and returns paths. The new reusable private storage helper is necessary to enforce an external root, real upload provenance, measured size, finfo/signature validation, opaque keys, integrity checks and authorized attachment delivery.

Apply `database/migrations/20261021_guarantor_evidence.sql` after the loan and authenticated-consent migrations. It is additive and replay-safe; do not drop versions/history or delete stored files as an automatic rollback.

Explicit deployment settings:

- `PRIVATE_DOCUMENT_STORAGE_ROOT`: an existing absolute directory outside BOTH the actual server document root and application root. Provision restrictive service-account permissions/ACLs and backups; no public fallback or automatic account/path guessing exists.
- `DOCUMENT_SCANNER_ADAPTER`: optional until deployment setup, but required to make files reviewable/downloadable. It names a trusted server-managed PHP file outside the web/application/storage roots. It must return a callable accepting the internal local filename and returning `['status' => 'clean'|'infected'|'unavailable', 'engine' => 'actual scanner/version identifier']`. The adapter must call a real configured scanner, impose bounded connection/execution timeouts and avoid logging document names/content. Do not install a wrapper which simply marks every file clean.

No adapter, exceptions, malformed results or unavailable scanning leave files quarantined. Signatures validate file format, not absence of malware. Production scanning remains a deployment dependency; tests simulate the external provider explicitly and do not certify any real scanner.

## API

Member JWT route: `auth_api/api/loans/guarantor-evidence.php`.
Administrator session route: `auth_api/api/admin/guarantor-evidence.php`; POST also requires verified same-origin information.

- GET `action=requests&loan_request_id=ID`: server-bound invitation IDs and current loan terms hash. A named guarantor sees their own invitation; a borrower sees their loan's invitations.
- GET `guarantor_request_id=ID&limit=20&before_id=CURSOR`: `data.items` and `data.next_cursor`. Limit is at most 50. Metadata includes `current_terms` and `is_latest`.
- POST multipart `guarantor_request_id`, `evidence_kind` (`approval` or `acknowledgement`), `upload_key`, file field `evidence`. PDF/JPEG/PNG, actual measured maximum 5 MiB. A stable 16–128 character upload key binds actor, invitation, kind, current terms and actual bytes. Identical retries return the original record; conflicting reuse fails. A deliberate new upload uses a new key and creates an immutable version.
- GET `action=download&document_id=OPAQUE_ID`: authorized clean-only attachment, verified hash/size, nosniff and private no-store headers. No filesystem path or original filename is returned.
- Staff POST JSON/form `action=review`, `document_id`, `decision` (`accepted` or `rejected`), `notes`. Rejection requires a reason. Decisions are pending-to-final; identical actor/decision/notes retries do not create history duplicates. A different decision requires a new version or controlled amendment.
- Staff POST `action=rescan`, `document_id`: runs the configured provider for pending evidence. Reviewed/finalized records require a controlled amendment workflow.

Rejected invitations must be renewed before new evidence/review. Approved, disbursed, cancelled and rejected loans cannot be silently amended. A newer pending/rejected/stale approval blocks an older acceptance. Upload/review never directly sets authenticated-guarantor provenance fields.

## Transactions, retention and verification

The service owns each complete transaction and locks loan, invitation and evidence in that order. It records version, source loan/invitation/member, terms snapshot/hash, actual content hash/type/size, scan result, uploader and staff review provenance plus append-only service history. Authorization callbacks are trusted internal composition; clients cannot select policies, paths, actors or scan status.

A pre-commit database failure removes the newly staged file. A lost commit acknowledgement may mean the record committed, so its file is deliberately retained. Reconcile the opaque storage key against committed metadata before any orphan cleanup. Back up database metadata and private bytes together; restoration must preserve their hashes. No retention deletion or evidence release policy is invented here.

Tests: `php tests/private_document_storage_test.php`; set `COOP_EVIDENCE_MYSQL_TEST=1` and run `php tests/guarantor_evidence_http_database_test.php` for the disposable `coop_test_evidence` database on loopback port 13307, actual local HTTP multipart/JWT/session routes and explicitly simulated external scanner responses.
