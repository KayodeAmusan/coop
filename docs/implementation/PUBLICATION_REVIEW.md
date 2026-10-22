# Publication review

22 October 2026. Requested destination: public `KayodeAmusan/coop`. This record is a publication blocker inventory, not a clean-source certification. The coordinating agent owns upload, repository identity and Git authentication.

## Current blockers

- The GitHub connector's attempted `create_file` operation returned HTTP 403, `Resource not accessible by integration`. No upload succeeded. Repository write authorization/app installation or an authenticated Git client is required.
- Existing upstream history contains credential-bearing source. A normal history push would publish it even after current-file cleanup. Do not push that history to a public repository. Prepare a reviewed source-only snapshot with no inherited history after credential/privacy review; preserve the original working tree and its history locally.
- Current source still contains credential-like literals in legacy paths. Safe inspection returned filenames/line numbers only; no values were copied to this document. Review and remove active literals, replace runtime configuration appropriately, and rotate exposed credentials before deployment. No mass source sanitization or rotation was performed by this review.

## Source findings to inspect before publication

| Source location | Key or concern |
|---|---|
| `onesignal/oneSginalfunctions.php:20-21` | token constants |
| `auth_api/onesignal/send_notification.php:7` | `apiKey` |
| `auth_api/onesignal/save_notification.php:66,91` | `apiKey` |
| `auth_api/onesignal/device_list.php:8` | `apiKey` |
| `auth_api/api/duty/save_notification.php:45,65` | `apiKey` |
| `auth_api/email/config.php:5` | password configuration |
| `auth_api/email/email_manager.php:47` | token assignment |
| `classes/send_alert.php:134,279` | API credential assignments |
| `bank_statement_processor.php:238` | Authorization header; determine whether literal is a credential or placeholder |

Matches are review leads, not proof every value is live. Public contact emails, synthetic test literals and placeholder credentials must be distinguished from private identities/secrets by source inspection. This scan was limited to source patterns and selected legacy files; archives, binary documents, SQL data, spreadsheets and images still require privacy review. No complete PII clearance is claimed.

## Snapshot exclusions

Exclude `.git`, `.env*` (retain reviewed `env.example` only), `config.env`, `vendor`, `node_modules`, `work`, runtime logs/caches/sessions, private storage, uploads, member files, backups, database dumps containing records, private keys, generated test artifacts and machine-specific configuration. Review explicitly tracked files too: `.gitignore` does not remove files already tracked, and broad existing ignores can hide required application source. Do not rely on `git add .` as a publication filter.

Retain reviewed native source, tests, migrations, `composer.json`/`composer.lock`, public assets and documentation. Validate a snapshot manifest and dependency completeness, scan the exact publication artifact, and verify no application file was omitted merely because of legacy broad ignore rules before upload. Keep durable AGENTS instructions and the historical continuation record; label corrections rather than rewriting claims as verification.
