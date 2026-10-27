# POS operations: inventory, cash sales, discounts, and returns

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
  customer, manager/admin discounts, tax, cash received, change due, and a completed sale receipt.
- **Sales history:** branch-local reference/date filters and totals; cashiers see
  only their own sales, while managers/admins see the current branch's sales.
- **Returns:** manager/admin partial/full cash refunds with explicit optional restocking.
- **Receipts:** separate print-friendly original-sale and refund records.
- **Overview:** today's sales, refunds processed, net activity, and low-stock counts.
  Cashier visibility follows the original sale's cashier, including refunds.
- **Cash drawers:** opt-in per-branch personal tills, physical cash movements, closing
  counts, and variance reviews. Cashiers see only their own tills.
  See [cash-drawer operations](cash-drawers.md).

This phase does **not** charge payment cards, initiate mobile payments, or connect
to a payment provider. Cashiers must physically verify cash before confirming.
It does not implement refund reversal, voids, credit sales, fractional stock,
product variants, supplier payments, transfer reversals, fiscal-device integrations, or statutory
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
| Apply checkout discounts / record cash refunds | Current branch | Current assigned branch | No |
| View sale/refund receipts and history | All sales in current branch | All sales in current assigned branch | Own sales in current assigned branch |

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
  the subtotal **after discount**. Aggregate tax is rounded **half-up once** to the currency's
  minor unit. Example: BDT 25.00 at 7.50% produces BDT 1.88 tax, total BDT 26.88.
- Server calculations ignore posted totals. Each line carries its quoted price
  and the cart carries its quoted tax rate; stale quotes are rejected for review.
  A failed checkout can restore the cart with current prices, which must be
  reviewed before submitting again.
- Currency cannot change once a branch has products (including archived ones),
  sales, or any till session. This avoids reinterpreting stored amounts in another currency.
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

All catalog, stock-adjustment, customer, and refund writes use the same branch-row lock
ordering. This is a conservative per-branch serialization strategy: reliable for
this phase, but not designed for unlimited parallel tills. Production MySQL
verification includes genuinely parallel checkouts competing for the last unit
and duplicate simultaneous submissions, plus competing refunds and refund/checkout stock updates. SQLite is used for lightweight feature
and browser tests, not as evidence of MySQL-style row locking.

Every checkout has an idempotency UUID and a unique branch/key constraint. An
identical retry by the same cashier returns the original receipt without changing
stock again. Reusing that key for a different payload is rejected. Stock
adjustments have separate idempotency keys and expected-stock checks to prevent
replayed adjustments or overwriting a stock change made since the form was opened.

Completed sales have no update/delete routes. Receipts snapshot item names/SKUs,
prices, quantities, customer details, branch identity/footer, cashier name,
currency, timezone, tax rate, discount/reason/authorizer, and payment amounts. Later catalog/settings edits do
not rewrite receipts. Sale times are stored in UTC. Receipts use the sale's snapshot timezone; history
rows, history date filters, and dashboard day boundaries use the current branch's
timezone.

## Discounts and cash returns

**Discounts:** an active admin/manager signs in as themselves and selects a fixed
currency amount or a percentage (up to 100%, with two decimal places) at checkout.
The amount must be positive and cannot exceed the gross subtotal; a reason of
3–500 characters is required. The receipt retains the type, normalized value,
actual reduction, reason, and authorizer identity/name. Cashiers cannot apply a
discount or use a delegated manager PIN. Discounts cannot be added retroactively.
Aggregate tax is calculated on the discounted subtotal, half-up to one minor unit.
A 100% discount is supported, with zero cash due.

**Returns:** open the original sale receipt and choose **Record a refund**. Select
whole units up to the remaining quantity, enter a reason, and explicitly confirm
the cash refund. The live preview uses the same integer rounding as the server.
Do not pay out twice after a network error: verify the sale's refund history first.
Only cash sales can use this workflow; it records a physical cash payout, not a
provider refund. Zero-cash returns of fully discounted goods still consume the
returned quantities and may restock inspected goods.

Restocking is **off by default**. Check it only for inspected sellable units. The
checkbox applies to all selected units on that line; split a return into separate
records when only some units are sellable. Unchecked returns leave inventory
unchanged. Restocking an archived product is allowed but does not reactivate it.
Inventory limits still apply. Refunds, lines, and restock movements commit together.

Each refund has a branch-scoped UUID/hash. An identical retry by the same staff
member returns the existing refund; changed payloads conflict. A stale returned
quantity rejects the form even if units remain, preventing a second operator from
unknowingly using an outdated refund quote. Reload, inspect history, and review
remaining quantities before trying again. Original sales cannot be edited or
deleted, and refunds cannot be edited or reversed in this phase.

### Exact allocation and partial-return rounding

All refund amounts come from the original sale snapshots, never current product
prices or branch tax rates. The original order discount is allocated to gross
line values proportionally using largest remainder, with ties going to the lowest
sale-item ID. Original aggregate tax is then allocated to the discounted line
values the same way. Multiplication/division avoids overflowing 64-bit integers
at the supported monetary limits; no floating-point money or BCMath is required.

For each line, net goods and tax are independently refunded as:
`round(original line amount × new cumulative returned / sold) −
round(original line amount × prior returned / sold)`, using half-up rounding.
This makes a complete return refund exactly the original paid amount across any
sequence of partial returns. Individual partial refunds may differ by a minor
unit. Example: two BDT 10.00 items, 10% discount, 7.5% tax pay BDT 19.35;
returning one then one refunds BDT 9.68 then BDT 9.67.

### Reporting dates and meaning

- **Sales & receipts:** filters original sale dates, shows the amount originally
  charged after discounts (including tax), **all** refunds against those matching
  sales regardless of processing date, and the net retained amount.
- **Refunds & returns:** filters refund processing dates; searches either refund
  reference or original sale reference. Cashiers can read refunds only for their
  own sales; managers/admins can read the selected accessible branch's refunds.
- **Today:** sales charged today less refunds processed today, including returns
  of older sales. This can be negative. It is not cash-drawer reconciliation,
  profit, or a statutory net-revenue/tax report.
- Times are stored in UTC. Ledger date boundaries use the current branch timezone;
  refund receipts retain the processing timezone and original-sale identity.

## Purchasing

Use **Suppliers** and **Purchasing** for supplier deliveries rather than generic
stock adjustments. Purchase orders support partial receiving and closing short,
with original costs and traceable goods receipts. No supplier payments or cash
movements are recorded. See [purchasing operations](purchasing.md).

## Stock transfers

Use **Stock transfers** for inter-branch movements. Dispatch deducts source stock;
only accepted receiving adds destination stock. Partial deliveries, damaged goods,
and confirmed loss have distinct dispositions. See [stock transfers](stock-transfers.md)
for permissions, matching SKUs, recovery, and rollout.

## Reporting

**Reports & analytics** provides manager/admin branch reports and CSV downloads.
Sales activity uses separate sale and refund processing dates, unlike the sale-date
cohort in Sales & receipts. Stock movement reports and a current low-stock list are
available separately. See [reports and exports](reports.md) for metric definitions,
timezones, privacy, limits, and rollout.

## Setup and operation

1. Back up your database and deploy the code with the existing `.env`/`APP_KEY`.
2. Run `composer install`, `php artisan migrate --force`, and
   `npm ci && npm run build`; clear/rebuild application caches normally.
3. Select a branch. As an admin/manager, open **Products & stock** and create
   products with selling prices and opening quantities.
4. Use **Stock ledger** for audited count corrections; use **Purchasing** for
   supplier deliveries and **Stock transfers** for inter-branch movements. Enter a
   signed change and a meaningful reason; negative resulting stock is rejected.
5. Add customer records if desired, or sell to a walk-in.
6. Open **Point of sale**, search/add products, verify quantities and totals,
   record cash received, and choose **Complete cash sale** only after receiving it.
7. Print the resulting receipt or reopen it through **Sales & receipts**.
8. To return units, a manager/admin opens the original receipt, selects **Record a
   refund**, reviews quantities and disposition, confirms authorization, and saves
   the separate cash refund receipt. Use **Refunds & returns** for processing-date searches.

Do not run migration rollback against live sales without a verified backup and
recovery plan: rolling back the adjustments migration deletes refund records and
discount snapshots; rolling back the earlier operations migration removes sales
and inventory tables. Neither is a safe way to undo a transaction.
The cash-drawer migration also removes reconciliation history and associations on
rollback. Enable tracking explicitly after deployment; see [rollout instructions](cash-drawers.md).
No demonstration products/customers/sales are seeded into production.

## Verification

`php artisan test` covers money arithmetic, role and branch enforcement, stale
forms, validation, stock adjustments, tax rounding, insufficient cash/stock,
idempotent retries, immutable receipts, discount/refund rounding, full-return
conservation, restock limits, authorization, net reports, and timezone date filtering, alongside
all prior management/authentication tests.

`npm run test:e2e` uses the existing isolated browser fixtures and a real Laravel
server. It creates products/customers, records a cash sale on a mobile viewport,
checks print styling, verifies stock ledger balances, and confirms that subsequent
product edits do not alter the receipt. It also applies a discount, checks two
partial-return previews against server totals, restocks only selected units, and
prints both receipt types. See the README for safe test setup.

CI also runs the PHP regression suite against MySQL 8.4. The twenty-nine forked concurrency
tests are intentionally skipped in SQLite jobs and executed on MySQL with `pcntl`.
The MySQL service and its fixed test credentials are ephemeral CI fixtures only.
