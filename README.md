# Sunset Sales · POS Management

A Laravel 13 / Tailwind CSS 4 workspace for a business with multiple branches.
The application provides **staff management, branch settings, products and stock,
customers, cash checkout, discounts, returns, till reconciliation, purchasing, and printable receipts**. `/pos` is live
checkout; `/pos-preview` preserves the old static design and remains explicitly
labeled as non-transactional. See [POS operations](docs/pos-operations.md) for
pricing, tax, permissions, transaction safety, and current limits.

## Implemented

### POS operations

- Branch-local products, SKUs/barcodes, prices, stock thresholds, and archiving.
- Audited stock adjustments with replay protection and non-negative balances.
- Customer records and optional walk-in checkout.
- Server-calculated cash sales with exact integer money, tax, change, and atomic
  stock deductions. No real payment-provider integration is included.
- Duplicate-checkout protection and immutable, printable receipt snapshots.
- Branch-scoped sales history; cashiers see only their own sales.
- Manager/admin fixed or percentage discounts with a required reason and authorizer snapshot.
- Partial/full cash refunds, optional restocking, separate refund receipts and ledger.
- Opt-in per-branch personal tills, cash-in/out ledger, immutable closing counts,
  and manager-reviewed variances. See [cash drawers](docs/cash-drawers.md) for rollout.
- Branch-local suppliers, immutable purchase orders, partial/full receiving, and
  stock-linked goods receipts. See [purchasing operations](docs/purchasing.md).
- Net retained sales history and today's sales/refund activity, plus low-stock counts.

Stock transfers, card/mobile integrations, refund reversal, and fractional-unit
products remain outside this phase. **Do not use live checkout to simulate sales
on a production database.**

### Staff and branch management

- Login/logout with login throttling, session regeneration, and active-account checks.
- Admin-managed staff accounts, search, role/status/branch filters, and pagination.
- Admin, Manager, and Cashier roles with server-side authorization.
- Multiple branch assignments per manager/cashier and a validated branch switcher.
- Read-only team directories scoped to the manager's current assigned branch.
- Per-branch name, code, contact details, address, currency, timezone, tax-rate
  setting, and receipt-footer setting, with persistent validation-backed storage.
- Forced password changes for newly created/reset accounts; self-service password
  changes require the current password.
- Deactivation rather than hard deletion. Account updates revoke sessions and API
  tokens; an old session cannot become valid again after reactivation.
- Responsive management screens, onboarding/empty states, and validation feedback.

### Access model

This is **one business with multiple branches**, not a multi-tenant SaaS platform.
A user's role is global; managers and cashiers can be assigned to multiple branches.

| Capability | Admin | Manager | Cashier |
| --- | --- | --- | --- |
| Access active branches | All | Assigned only | Assigned only |
| View assigned branch overview | Yes | Yes | Yes |
| View branch team directory | Yes | Assigned branches | No |
| Create/update/deactivate staff | Yes | No | No |
| Assign roles and branches | Yes | No | No |
| Create/update/deactivate branches and settings | Yes | No | No |
| Change own password | Yes | Yes | Yes |

An administrator cannot deactivate or demote their own account. At least one
active administrator and, once created, one active branch must remain. Staff
without an active branch assignment see an explanatory empty state rather than
another branch's data. Branch access is revalidated on every request.

## Install and bootstrap

Requirements: PHP **8.3+**, Composer 2, a supported database, and Node.js
**20.19+ within 20.x or 22.12+**. CI covers PHP 8.3, 8.4, and 8.5 with Node 22.
Install Laravel's PHP extensions plus your PDO database driver; tests need
`pdo_sqlite` and XMLWriter.

```sh
composer install
cp .env.example .env
php artisan key:generate
# Configure your database and APP_URL in .env before migrating.
php artisan migrate
npm ci
npm run build
php artisan pos:create-admin
php artisan serve
```

`pos:create-admin` prompts for the administrator's name, email, and a hidden,
confirmed password. There are **no default administrator credentials**, no public
registration, and no automatically privileged existing users.

1. Sign in at `/login` using the administrator account you created.
2. Create the first branch in **Branches & settings**.
3. Add staff in **User management**, select their role, and assign active branches.
4. Share each temporary password through a secure channel. Staff must replace it
   before using the workspace. This phase does not send invitation emails.
5. For a forgotten password, an admin sets a new temporary password in the staff
   edit screen. Administrative host access can create an additional admin with
   the console command if required; existing accounts are not silently promoted.

### Upgrading an existing installation

Back up the database and preserve `.env` and `APP_KEY`. Install the committed
lockfiles, run all pending migrations, including the **POS operations, discounts/refunds, cash-drawer, and purchasing migrations**, rebuild frontend assets,
and clear/rebuild configuration, route, and view caches. Existing users default
to **Cashier**, keep their current active status/password, and have **no branch
assignments** until an administrator assigns them. Create an initial administrator
with `pos:create-admin`; choose an email not already used by an existing account.

Use HTTPS and appropriate secure session-cookie configuration in production.
Validate the deployment on staging with your real database before rollout.

## Tests

```sh
composer validate --strict
composer check-platform-reqs
composer audit
php artisan config:clear
php artisan test
npm audit
npm run build
```

PHPUnit uses an in-memory SQLite database and test-only encryption key. Coverage
includes branch isolation, role escalation attempts, account/session revocation,
password changes, admin setup, settings validation, and the original Sanctum /
request-forgery regression tests. CI also checks native PHP syntax, migrations,
and framework caches. Adjustment tests cover authorization, exact-cent allocations,
partial returns, stale quotes, duplicate refunds, restock limits, and net reporting.
MySQL CI additionally runs twenty real parallel tests for checkout overselling/replay,
refund over-return/replay/staleness, stock consistency, till open/close replay,
payments racing till closure, and purchasing/receiving races.
These concurrency tests are intentionally skipped on SQLite.

### Browser acceptance tests

Playwright exercises the real Laravel server and production assets: admin branch
and staff creation, settings persistence, first-login password changes, manager
branch isolation, authorization failures, mobile cashier navigation, product and
customer creation, cash checkout, discounts, partial/full returns, optional
restocking, till activation and reconciliation, variance review, print styling,
stock adjustments, supplier ordering/receiving, and receipt preservation.

Use a **dedicated disposable database**. Never use production data or credentials
with the browser fixture seeder. A testing environment alone does not make an
existing database safe to use.

```sh
# Use these environment variables only for this disposable test session.
export APP_ENV=testing
export APP_URL=http://127.0.0.1:8000
export DB_CONNECTION=sqlite
export DB_DATABASE=/tmp/sunset-browser.sqlite
export E2E_ADMIN_PASSWORD="$(openssl rand -hex 24)"
touch "$DB_DATABASE"
php artisan config:clear
php artisan migrate
php artisan db:seed --class=BrowserTestSeeder
npm run build
npx playwright install --with-deps chromium
npm run test:e2e
```

The browser fixture seeder refuses to run unless `APP_ENV=testing` and
`E2E_ADMIN_PASSWORD` are set. It is not called by the default database seeder.
Playwright starts and stops the local Laravel server itself. Use a fresh disposable
SQLite file when repeating the fixture setup. Traces and screenshots are excluded
from Git.

## Dependency upgrade history

`composer.lock` resolves Laravel **13.38.0**; both lockfiles are committed. Use
`composer install` / `npm ci` for reproducible installations. See
[the Laravel 13 upgrade notes](docs/laravel-13-upgrade.md) for the framework and
frontend migration history. The staff/branch migrations above are a subsequent
feature addition, not part of that earlier dependency-only upgrade.
