# Suppliers, purchase orders, and receiving

## Scope and access

Managers and administrators can maintain suppliers, create purchase orders, and
receive goods in their accessible **current branch**. Cashiers cannot view these
screens, cost details, or receiving receipts. Supplier/product/order/receipt IDs
are scoped on the server, and mutating forms carry their originating branch ID.
A branch switch in another tab rejects an old form rather than writing elsewhere.

Suppliers are branch-local contacts, with a unique branch/code pair, contact name,
email, phone, address, and internal notes. Archived suppliers retain their codes
and history. Archiving prevents new orders but does not block receiving already
placed orders. Updates require the current supplier version to prevent stale edits.
There are no supplier, order, or receipt hard-delete routes.

This phase is **not** accounts payable or inventory accounting. It does not record
supplier payments/debt, tax credits, freight, landed cost, moving-average cost,
COGS, profit, supplier returns, or inter-branch transfers. No cash-drawer movement
is made when ordering or receiving, even in branches with cash tracking enabled.
No open till is required for purchasing. Selling prices never change on receipt.

## Create an order

1. Select the branch and open **Suppliers**. Add an active supplier with its own
   unique code. Codes are normalized to uppercase.
2. Ensure the goods exist as active products in this branch. A product may have
   zero stock; purchasing is intended to replenish it.
3. Open **Purchasing → New purchase order**. Choose the supplier, optionally enter
   a quotation/reference, delivery date, and order notes.
4. Search by name, SKU, or barcode. The picker shows up to 24 matches; refine the
   search for more specific results. Add each product once and enter whole-unit
   quantities and agreed **unit costs**, not selling prices.
5. Review the goods-value preview and confirm. Saving finalizes the order; no
   draft/edit workflow exists in this phase. Print/share it through your normal
   process. The application does not email or transmit it to suppliers.

The original supplier and branch contacts, product name/SKU, quantities, unit
costs, currency, timezone, creator/name, and ordered timestamp are snapshotted.
Later catalog/contact/settings edits do not rewrite the order or its receipts.
Internal supplier notes are not copied into printable purchase orders.

Quantities and unit costs cannot be edited after saving. If an order is wrong,
close its outstanding quantities with a reason and create a replacement; do not
pretend a physical receipt occurred to close it out. Already-received goods remain
in stock. There is no undo or supplier-return workflow in this phase.

## Receive inspected goods

Open an **Open** or **Partially received** order and choose **Receive goods**.
Enter the accepted units actually delivered, a delivery/packing-slip reference,
and inspection/discrepancy notes. Confirm that you inspected the goods and have
not already recorded this delivery. The shortcut selects all remaining units,
but never substitutes for a physical count.

- Only selected positive quantities are received. Partial receipts are supported.
- Quantities cannot exceed the original order's outstanding units.
- Damaged/rejected goods must be excluded; this adds sellable inventory directly,
  not a quarantine or inspection-hold balance.
- Archived products can fulfill existing orders but remain archived/unavailable
  for sale. An archived supplier can fulfill an existing order as well.
- Each receipt records original order costs, accepted quantities, actor/name,
  delivery reference, inspection notes, and UTC timestamp. It has its own `G-`
  reference, immutable lines, printable goods receipt, and stock-ledger links.
- A receipt, all accepted quantities, stock increases, and inventory movements
  commit in one transaction. A stock-limit or validation failure rolls all of
  them back; no partial receipt or partial stock update remains.
- Receipt quantities are deliberately reset after errors. Reload and inspect
  history, then recount/reselect before retrying a stale receiving form.
- Receipts cannot be edited or reversed. Record and investigate errors through
  the stock-adjustment procedure where appropriate; this does not rewrite the
  original receiving receipt or purchase-order fulfillment.

External delivery references are searchable but **not unique**: a packing slip or
invoice may span multiple deliveries/orders. Replay protection uses the submitted
UUID, not the external reference. A new form/new key represents a new operation;
always inspect receipt history after a network error before submitting again.

## Order lifecycle

- **Open:** no units received.
- **Partially received:** some but not all units received.
- **Fully received:** all ordered units received, including zero-cost goods.
- **Closed short:** remaining unreceived units were explicitly canceled, either
  before any delivery or after partial receipts.

Closing short requires a reason, confirmation, and a current order version. It
records closer/name and time but never changes original quantities/costs, reverses
receipts, changes cash, or removes received stock. Closed orders cannot be reopened.
Fully received orders need no short-close operation. Historical receipt retries
still return the original record after an order is closed or fully received.

The screen separates original ordered goods value, received goods value, and
unreceived value (or value closed short). These are not payable/payment balances.
The printable purchase order retains its original ordered lines; live fulfillment
and cancellation information are shown separately on screen.

## Monetary and inventory rules

All values are integer minor units on the server and BigInt in browser previews.
JPY uses whole yen; all other supported currencies use two decimals. Unit costs
may be **zero** for free goods, but cannot be negative. Costs exclude tax, freight,
and fees; branch sales-tax settings are deliberately not applied to purchases.

An order supports up to 100 distinct products, 1–10,000 units per line, unit costs
up to 1,000,000,000 minor units, and total goods value up to 1,000,000,000,000 minor
units. Existing inventory remains capped at 1,000,000,000 units per product.
These limits fit exact 64-bit PHP arithmetic. The server ignores posted totals,
source actor IDs, received balances, and other unvalidated accounting fields.

## History, dates, and safety

Purchase-order filters use **ordered dates**; receiving history uses **processing
dates**. Both interpret date ranges in the current branch timezone. Purchase
orders retain their original timezone; goods receipts retain the timezone at
receiving. Date filters include the entire local day with an exclusive next-day
boundary. Expected delivery is an optional date-only planning field, not a
promise, automatic receipt, or overdue notification.

All purchasing and supplier writes use the existing branch-row lock first,
refresh actor access, and recheck manager/admin authorization. Receiving locks the
order, its lines, and products in deterministic order. This coordinates with
checkout, returns, manual adjustments, and other receiving operations.

Orders, receipts, and short-closes have branch-scoped UUID/hash replay protection
bound to the original actor. Same-payload retries return the original record;
changed payloads conflict. Every receipt advances the order version; a concurrent
receipt or short-close invalidates another open form even if some units remain.
Database uniqueness and restrictive foreign keys protect source relationships.

Branch archiving pauses operational access without deleting purchase history.
Reactivate/select it to receive or close outstanding orders. Currency is locked
once products, sales, tills, or purchase orders exist. This phase does not
implement cross-currency purchases or inter-branch receipt allocation.

## Deployment and testing

Back up the database and preserve `.env` / `APP_KEY`. Install locked dependencies,
run `php artisan migrate --force` (including
`2026_10_27_000006_add_suppliers_and_purchasing.php`), run `npm ci && npm run build`,
and clear/rebuild framework caches. Verify on staging before rollout. Existing
sales, discounts, refunds, cash sessions, stock, and movements are not backfilled
or revalued. No demonstration suppliers or orders are seeded into production.

Migration rollback deletes purchasing/supplier history and inventory receipt
associations. It does **not** subtract previously received stock and is not a safe
business reversal; use only with a verified backup/recovery plan.

Regression coverage includes role/branch boundaries, supplier archiving and stale
edits, snapshot preservation, exact/zero/maximum/JPY costs, receiving and closing,
actor-bound retries, forged/duplicate lines, limits, stale forms, date filters,
and preserving existing transactions during migration. Browser tests cover
supplier creation, out-of-stock purchasing, partial/full delivery, closing short,
printing, mobile receiving, stock/history links, and cashier denial.

Seven real MySQL/pcntl races exercise duplicate ordering/receiving, full/partial
competing receipts, receiving versus closing, simultaneous orders near the stock
limit, and checkout versus receiving. They join the thirteen existing races;
SQLite deliberately skips these row-locking tests, while MySQL forbids skips.
