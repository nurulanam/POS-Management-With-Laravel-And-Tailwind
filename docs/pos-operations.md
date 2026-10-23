# POS operations: inventory, cash checkout, and receipts

## What is live

This phase adds real, database-backed operations to the existing staff and branch
workspace. The old `/pos-preview` route remains a design-only prototype; the new
**`/pos` route is live checkout**.

- **Products:** branch-specific name, SKU, optional barcode/category, selling
  price, opening stock, low-stock threshold, and active/archive status.
- **Inventory:** whole units, an append-only movement ledger, reasoned positive
  and negative adjustments, and automatic deductions on completed sales.
- **Customers:** branch-local contact records; walk-in checkout needs no record.
- **Cash checkout:** searchable/paginated catalog, editable cart, optional
  customer, tax, cash received, change due, and a completed sale receipt.
- **Sales history:** branch-local reference/date filters and totals; cashiers see
  only their own sales, while managers/admins see the current branch's sales.
- **Receipts:** print-friendly browser receipts preserving original sale details.
- **Overview:** today's sales/count/total and manager/admin low-stock counts in
  the selected branch. Cashier totals include only that cashier's sales.

This phase does **not** charge payment cards, initiate mobile payments, or connect
to a payment provider. Cashiers must physically verify cash before confirming.
It does not implement discounts, refunds, voids, credit sales, fractional stock,
product variants, purchasing, transfers, fiscal-device integrations, or statutory
tax filing. Printed receipts are application sale records, not a guarantee of
compliance with any jurisdiction's tax-invoice requirements.

## Permissions and branch boundaries

| Action | Admin | Manager | Cashier |
| --- | --- | --- | --- |
| Maintain product prices/catalog | Any active branch | Assigned active branches | No |
| Receive/adjust inventory | Any active branch | Assigned active branches | No |
| Search and create customers | Current branch | Current assigned branch | Current assigned branch |
| Edit/archive customers | Yes | Current assigned branch | No |
| Complete cash checkout | Current branch | Current assigned branch | Current assigned branch |
| View receipts/history | All sales in current branch | All sales in current assigned branch | Own sales in current assigned branch |

All operational routes require an active branch. Product/customer/receipt IDs are
scoped to it on the server. Mutating forms include their originating branch ID;
a different active branch in another tab causes validation failure rather than
writing the old form into the new branch. Switching branches or leaving checkout
clears the unsaved cart. There is no shared or persistent browser cart.

Archiving a product prevents new sales but preserves its stock and history.
Archiving a customer prevents selection in new sales but retains old receipts.
Archiving a branch makes its operational screens unavailable; an administrator
must reactivate/select it to inspect its historical receipts. There are no hard
product/customer/sale deletion endpoints.

## Price and tax rules

- Supported branch currencies use **two decimal places**, except **JPY**, which
  uses whole yen. Currency codes come from `config/pos.php`.
- Values are stored as integer **minor units**, never floating-point amounts.
  The browser uses integer `BigInt` arithmetic for the cart preview as well.
- Selling prices are tax-exclusive. The branch's percentage tax rate applies to
  the full subtotal. Aggregate tax is rounded **half-up once** to the currency's
  minor unit. Example: BDT 25.00 at 7.50% produces BDT 1.88 tax, total BDT 26.88.
- Server calculations ignore posted totals. Each line carries its quoted price
  and the cart carries its quoted tax rate; stale quotes are rejected for review.
  A failed checkout can restore the cart with current prices, which must be
  reviewed before submitting again.
- Currency cannot change once a branch has products or sales, even archived
  products. This avoids reinterpreting stored amounts in another currency.
  Use a separate branch for a different currency until a deliberate conversion
  workflow is implemented.
- A sale is limited to 100 distinct products, 10,000 units per line, a subtotal
  of 1,000,000,000,000 minor units, and cash received up to 2,000,000,000,000 minor
  units. Product price is capped at 1,000,000,000 minor units; inventory at
  1,000,000,000 units. Use 64-bit PHP.

## Transaction and replay safety

Checkout runs in one database transaction. It locks the branch row first, then
locks and revalidates products, quantities, prices, customer membership, settings,
and stock. It records the sale, line snapshots, inventory deductions, and stock
movements together. Failure leaves no partial sale or stock deduction.

All catalog, stock-adjustment, and customer writes use the same branch-row lock
ordering. This is a conservative per-branch serialization strategy: reliable for
this phase, but not designed for unlimited parallel tills. Production MySQL
verification includes genuinely parallel checkouts competing for the last unit
and duplicate simultaneous submissions. SQLite is used for lightweight feature
and browser tests, not as evidence of MySQL-style row locking.

Every checkout has an idempotency UUID and a unique branch/key constraint. An
identical retry by the same cashier returns the original receipt without changing
stock again. Reusing that key for a different payload is rejected. Stock
adjustments have separate idempotency keys and expected-stock checks to prevent
replayed adjustments or overwriting a stock change made since the form was opened.

Completed sales have no update/delete routes. Receipts snapshot item names/SKUs,
prices, quantities, customer details, branch identity/footer, cashier name,
currency, timezone, tax rate, and payment amounts. Later catalog/settings edits do
not rewrite receipts. Sale times are stored in UTC. Receipts use the sale's snapshot timezone; history
rows, history date filters, and dashboard day boundaries use the current branch's
timezone.

## Setup and operation

1. Back up your database and deploy the code with the existing `.env`/`APP_KEY`.
2. Run `composer install`, `php artisan migrate --force`, and
   `npm ci && npm run build`; clear/rebuild application caches normally.
3. Select a branch. As an admin/manager, open **Products & stock** and create
   products with selling prices and opening quantities.
4. Use **Stock ledger** for deliveries, damage, or count corrections. Enter a
   signed change and a meaningful reason; negative resulting stock is rejected.
5. Add customer records if desired, or sell to a walk-in.
6. Open **Point of sale**, search/add products, verify quantities and totals,
   record cash received, and choose **Complete cash sale** only after receiving it.
7. Print the resulting receipt or reopen it through **Sales & receipts**.

Do not run migration rollback against live sales without a verified backup and
recovery plan: rolling back this migration removes the new operations tables.
No demonstration products/customers/sales are seeded into production.

## Verification

`php artisan test` covers money arithmetic, role and branch enforcement, stale
forms, validation, stock adjustments, tax rounding, insufficient cash/stock,
idempotent retries, immutable receipts, and timezone date filtering, alongside
all prior management/authentication tests.

`npm run test:e2e` uses the existing isolated browser fixtures and a real Laravel
server. It creates products/customers, records a cash sale on a mobile viewport,
checks print styling, verifies stock ledger balances, and confirms that subsequent
product edits do not alter the receipt. See the README for safe test setup.

CI also runs the PHP regression suite against MySQL 8.4. The two forked concurrency
tests are intentionally skipped in SQLite jobs and executed on MySQL with `pcntl`.
The MySQL service and its fixed test credentials are ephemeral CI fixtures only.
