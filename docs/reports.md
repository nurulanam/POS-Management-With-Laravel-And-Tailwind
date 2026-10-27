# Branch reports and CSV exports

**Reports & analytics** is available to active admins and managers in the current
active branch. Admins switch between branches; managers can report only on assigned
branches. Cashiers cannot access report pages or download endpoints. There is no
consolidated cross-branch/cross-currency report in this phase.

## Sales activity: a processing-date report

`/reports` defaults to the last **30 calendar days including today**, using the
branch's current timezone. Choose inclusive From / Through dates, up to **366
calendar days**. A supplied Through date without From uses its preceding 29 days;
a supplied From without Through ends today. Explicit input dates must be between
2000-01-01 and 2099-12-31. Empty dates use the defaults. Future periods are allowed
and normally have no activity.

Sales are included by `paid_at`; refunds are included independently by
`refunded_at`. UTC boundaries are calculated for each local calendar day, including
23/25-hour daylight-saving days. MySQL timezone tables are not required.
Changing the branch timezone changes how historical events group in this report;
it does not alter the timezone snapshots on original receipts.

| Metric | Definition |
| --- | --- |
| Gross excluding tax | Original sales subtotals, before discounts |
| Discounts granted | Discounts on sales charged in the period; not reversed on refunds |
| Charged including tax | Original sales totals after discounts plus tax |
| Refunds processed | Original refund totals processed in the period, including tax |
| Net excluding tax | Gross minus discounts minus refund subtotals (already discounted) |
| Net tax activity | Tax on period sales minus tax on period refunds |
| Net activity including tax | Charged minus refunds processed |

All monetary calculations use integer minor units and original transaction values.
CSV money is exported as decimal amounts without currency symbols/thousands
separators, alongside an explicit currency column. JPY uses zero decimal places.
No floats are used for money. If imported/legacy data contains a different currency
in the selected branch/period, the report refuses to combine it (409); investigate
and reconcile that data rather than converting it implicitly.

**This differs from Sales & receipts:** that screen selects original sales by sale
date and subtracts *all* their refunds, irrespective of refund date. A refund today
for last month's sale appears in today's activity report, but not today's sale-date
cohort. Net activity can be negative. A partial refund counts as one refund receipt,
not a fully refunded sale. Daily links open the relevant sales or refund history.

Calendar days with no activity are included as zeros. Totals cover the entire
selected period. The daily CSV has one row per date, with branch ID/name,
timezone, currency, generation time, counts, gross, discounts, charged amounts,
refund base/tax/total, and net base/tax/total. It is not affected by page parameters.

These are **operational activity reports**, not profit/COGS, inventory valuation,
revenue recognition, statutory tax filings, payment settlement, or till closing
reports. Cash received/change are not sales value. Till opening balances,
cash-in/out, purchases and transfers are intentionally excluded from sales totals.
Use Cash drawers for actual expected/count reconciliation.

## Stock movement report

`/reports/inventory` lists branch inventory ledger entries within the selected local
date range (default last 30 days, maximum 366). Filter by movement type or branch-local
product ID, including archived products. Product ledgers offer a direct
**Movement report for this product** link; IDs also appear in report rows.

Categories are determined by structured record links, never free-text reasons:

- Sale deduction
- Return restock
- Purchase receipt
- Transfer received
- Transfer dispatched
- Opening / adjustment (combined, since legacy rows do not have a typed opening flag)

Rows show the timestamp, movement ID, current product name/SKU, type, signed
quantity change, recorded balance, reason, and links to source documents. The
recorded balance is the stock **immediately after that movement**, not the ending
stock of a filtered report. Records are newest ledger ID first. Product names and
SKUs come from the **current catalog** because the movement table does not preserve
name snapshots; original sale/purchase/transfer documents retain their own names.

Inventory summaries count matching records and distinct products. Units of unlike
products are not added into a misleading consolidated stock balance. This report
is not an as-of-date inventory reconstruction or inventory valuation.

Only physical stock effects appear: non-restocked refunds and transfer damage/loss
have no destination stock additions. Use transfer receiving/discrepancy documents
for those dispositions. Outstanding purchases and in-transit goods are not on hand.

The CSV includes every matching page (up to the limit), timestamps with timezone
offsets, current identities, quantities, recorded balances, reasons and source
record IDs. It omits customer contact details and staff personal data.

## Current low stock

`/reports/low-stock` is a **current** replenishment list, independent of date filters
and stock-movement filters. It includes active products where `stock <= reorder_level`,
including zero-stock products with a zero threshold, and excludes archived products.
Ordered by stock then product ID. There is no automatic reorder quantity, supplier
selection, or deduction for incoming purchases/transfers.

The CSV contains branch/product IDs, current name/SKU, current stock, threshold and
generation time. No selling-price-derived valuation is presented.

## Consistency, limits and export safety

- Each report is a read-only business-data operation. No financial entries or stock
  changes are written. Normal authentication/session/rate-limit storage still applies.
- Multi-query reports run within a read transaction. On MySQL, top-level report
  transactions explicitly use **REPEATABLE READ**, including when the server's
  default is READ COMMITTED. SQLite uses its transactional read snapshot. Native
  tests commit a refund between the sales/refund queries and verify a coherent
  snapshot rather than a mix of before/after totals. MySQL and SQLite are tested;
  other database engines have not been certified for this report behavior.
- Separate page loads/downloads are fresh reads and may include newly committed
  activity. Generated timestamps identify the request, not a durable frozen report
  or a historical cutoff. Reports are not stored/scheduled and cannot be reproduced
  byte-for-byte after later catalog/timezone changes or refunds.
- Movement and low-stock pages paginate 50 records. Their CSV exports include all
  matching pages up to **10,000 rows**. More than 10,000 is rejected *before download
  headers*, never truncated. Narrow movement date/product/type filters; use a
  controlled administrator-managed database export for larger low-stock lists.
- Daily sales exports are bounded to 366 rows. Download routes are rate limited
  (10 requests per minute). Exports require the branch ID captured by the link;
  switching branches in another tab makes an old link fail rather than silently
  downloading another branch's data. Date/filter forms carry the same branch check.
- CSV uses UTF-8 with a BOM, quoted fields and CRLF line endings. Untrusted text
  with formula-like prefixes (including leading whitespace/control characters) is
  prefixed with an apostrophe to reduce spreadsheet formula-injection risk.
  Trusted generated negative monetary/quantity fields remain numeric. Names/reasons
  may therefore display a leading apostrophe when read by a plain CSV parser.
- Downloads use attachment headers, `nosniff`, and private/no-store caching. Report
  pages are also no-store. Downloaded files still contain business information;
  store/share them securely and do not enable spreadsheet macros or external links.

## Deployment

Back up the database and keep `.env` and `APP_KEY`. Install committed dependencies,
run `php artisan migrate --force`, rebuild assets with `npm ci && npm run build`,
and rebuild normal application caches. The reporting migration
`2026_10_28_000008_add_reporting_indexes.php` adds branch/date indexes for sales and
movements plus a branch/active/stock index for low-stock queries. It changes no
transaction data. Index creation can take time/locks on large tables: schedule a
maintenance window and validate duration on a staging copy.

Rolling back **this migration only** removes its three indexes, not transactions
or stock. Do not roll back an entire historical migration batch just to remove
reporting indexes: earlier migrations have destructive downs. No production data
or demonstration reports are seeded.

Before rollout, reconcile a known discounted sale and partial refund against the
daily report and CSV; check a refund for an earlier sale; verify timezone date
boundaries, stock movement links, a product exactly at threshold, and cashier denial.
See the README for isolated test setup. Automated coverage includes monetary
reconciliation, branch/role enforcement, stale tabs, DST/midnight, zero-day filling,
CSV quoting/formula safety, limits/all-page exports, original data preservation,
read-only behavior, concurrent refund consistency, mobile views and real downloads.
