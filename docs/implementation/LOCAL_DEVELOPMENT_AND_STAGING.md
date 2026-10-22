# Local development and staging

Updated 22 October 2026. This is a source-checked setup guide, not evidence of a successful fresh install or restore. The repository does not contain a verified complete legacy database bootstrap.

## Isolated development

1. Install PHP 8.2 with PDO MySQL, BCMath, OpenSSL, fileinfo, mbstring and the extensions required by Composer (including GD/ZIP for spreadsheet workflows). Install Composer and a disposable MySQL/MariaDB instance.
2. Run `composer install` against `composer.lock`. The manifest installs PHPMailer, dotenv, PhpSpreadsheet and Guzzle; it does not install mPDF/TCPDF. Legacy reports requiring other libraries need separate dependency verification.
3. Copy root `env.example` to root `.env`. Set only disposable database coordinates and a unique development JWT secret. Do not copy production records or credentials. Never commit `.env`.
4. Obtain an authorized synthetic legacy base schema. Root `SETUP_FULL_ACCOUNTING_SYSTEM.sql` supplies accounting schema/seed data, not the whole application. Review migrations individually and apply required forward migrations in dependency order. Do not bulk-execute `database/migrations/` or apply `_down.sql` files during setup. Fresh-schema ordering remains unverified.
5. Follow [platform configuration](PLATFORM_CONFIGURATION.md) for the separate Auracle identity and external encryption key, and [guarantor evidence](GUARANTOR_EVIDENCE.md) for storage outside the web root and scanning. No default account is provided. An unavailable scanner must keep uploads quarantined.
6. From repository root run `php -S 127.0.0.1:8000 -t .` for development only. Starting PHP does not establish working DB routes. Use synthetic identities and prevent outbound providers at the environment boundary.

## Checks

`php tests/run.php` runs the repository checks that do not require the opt-in database environment; database skips are not passes. `php tests/run.php --mysql` requires disposable loopback databases and the runner's selected environment flags. Read fixture schema names before running; fixtures create/drop synthetic `coop_test_*` schemas. Never point these at a production server.

The local `work/run-evidence-with-db.php` wrapper references a machine-specific existing MariaDB configuration and is not a portable installation tool. It is excluded from publication. A fresh portable test-server bootstrap still needs implementation and verification. UI DOM tests simulate browser APIs; actual viewport, keyboard, screen-reader and network measurements remain outstanding.

## Staging acceptance still required

Create a restricted staging deployment with synthetic data and blocked live payments/messages. Rehearse backup and restore into another disposable database, restoring the separately protected configuration key where applicable. Verify migration order, accounting reconciliation, member isolation, provisioning, storage/scanner failure paths and browser journeys before accepting deployment. No restore rehearsal or production deployment is claimed here.
