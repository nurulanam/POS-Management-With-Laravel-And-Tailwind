# Inter-branch stock transfers

Transfers move **whole-unit quantities**, not money, between branches of the same
business. They are separate from purchases, sales, refunds, and generic stock
adjustments. There is no valuation, currency conversion, tax, payment, or till
entry; branch prices and purchase costs are unchanged. Different branch currencies
are permitted because no financial amount is transferred.

## Access and product identity

- Admins and managers can operate transfers. Cashiers cannot view or write them.
- Dispatch from the **current source branch**. The dispatcher must have access to
  **both active branches**. Admins have access to all active branches; managers need
  both assignments. Inaccessible destination catalogs are not exposed.
- Receiving happens in the **current destination branch**. Its assigned manager can
  receive even without access to the source branch. They can see the transfer's
  manifest and branch snapshots, not the source branch's broader inventory.
- History and receipts are visible only when the current branch is an endpoint.
- Products remain branch-local. Create both products first with **exactly matching
  SKUs**. The dispatcher sees both names and must confirm the goods and units are
  identical. A matching SKU alone is not proof of equivalent packaging.
- No automatic product creation, mapping changes, barcode matching, or stock pooling.
  The original product IDs stay fixed if names/SKUs later change. Receiving into an
  archived destination product is allowed without reactivating it. Review the
  original mapping before receiving; records are never silently redirected.

## Dispatch

1. Select the source branch, open **Stock transfers → Dispatch transfer**.
2. Choose an accessible active destination, search source products, and verify both
   product names against their shared SKU. Changing destination clears selections.
3. Enter whole quantities (1–10,000 per line, at most 100 distinct products), dispatch
   notes, and confirm product identity, unit packaging, and physical handover.
4. Save and print the `X-` dispatch note. Source stock is deducted atomically with
   the manifest and stock ledger entries. Destination stock is unchanged.

There is no draft or reservation. Do not click dispatch merely to obtain a quote.
Insufficient source stock rejects the **entire dispatch**. Notes, branch identities,
product names/SKUs, dispatched quantities, actor name, time and timezone are
snapshotted. Subsequent edits to those entities do not rewrite the original note.

## Receive, partially or in full

Open an incoming transfer in its destination branch and choose **Receive transfer**.
Check previous receipts, physically count the delivery, then classify each unit:

| Disposition | In transit | Destination sellable stock |
| --- | --- | --- |
| Accepted | Decreases | Increases |
| Damaged | Decreases | Unchanged |
| Confirmed missing | Decreases | Unchanged |
| Not yet arrived / not entered | Unchanged | Unchanged |

The three entered quantities together cannot exceed a line's remaining units.
Leave goods expected later in transit; do **not** declare them missing merely
because a delivery is partial. Damage/loss requires an additional explicit
manager/admin confirmation and explanatory notes. This is authorization by the
recording operator, not a separate second-person approval workflow.

Record a delivery/handover reference and inspection notes, confirm, then save.
Only accepted units create positive destination stock movements. Damaged goods
need separate physical handling: no quarantine inventory or disposal workflow is
implemented. A destination stock cap of 1,000,000,000 units is enforced atomically.
Any invalid line rejects the entire receipt.

A printable `Y-` receipt preserves each disposition, the recording actor, notes,
reference, local timestamp and timezone. The transfer is `in_transit` until **all
units are accounted for**, then `completed`. Completed does not mean all goods
arrived undamaged. History shows total sent, accepted, damaged, missing and in
transit, with direction/status/reference and branch-local dispatch-date filters.
The stock ledger links back to the transfer and its receiving history.

## Retries, concurrent work, and recovery

- Dispatch/receiving use actor-bound UUID keys and normalized payload hashes.
  With valid access and active branches, an exact retry returns its original
  document, including a receipt on a now-completed transfer. Changed payload with
  the same key is rejected (409); another actor's key is rejected (403).
- Receiving includes an expected transfer version. Two stale screens cannot both
  record against the same version, even if units would remain afterward. Reload,
  inspect history, and count again; error redirects intentionally clear quantities
  and confirmations and generate a new key/version rather than replay old counts.
- The delivery reference is an audit aid, **not a physical-duplicate guarantee**.
  The same delivery submitted under a fresh key after reload can be recorded again
  if enough units remain. Always check history after a network error before making
  a new request. Do not retry with a new key just because the response was lost.
- Transactions lock both endpoint branches in ascending ID order before products
  or transfer rows, coordinating with checkout, stock adjustments, purchasing and
  branch settings. Actors, role, session version, and memberships are rechecked
  after locking. Opposite-direction transfers use the same lock order.
- Branches cannot be deactivated while any incoming/outgoing transfer is in transit.
  Account for actual arrivals or documented damage/loss first. Do not invent loss
  merely to archive a branch. Completed history remains available at the active
  endpoint even if the other branch has since been archived.

**No cancellation, edit, reversal, reopening, or automatic return after dispatch.**
If goods physically return, receive/account for the original transfer and make a
new actual reverse transfer as appropriate. If an incorrect dispatch, mistaken
loss declaration, or later recovery needs correction, investigate and document
it with authorized stock adjustments/new physical transfers referencing the
original documents. Do not alter database counters or invent a second receipt to
undo history. Corrections are separate audit events, not linked reversal records.

## Deployment and rollback

Back up the database; retain `.env` and `APP_KEY`. Install committed dependencies,
run `php artisan migrate --force` (including
`2026_10_27_000007_add_stock_transfers.php`), rebuild assets with
`npm ci && npm run build`, and rebuild configuration/route/view caches normally.
No production transfer records or example stock are seeded. Existing products,
purchases, sales, refunds, cash records and balances are not modified by migration.

Validate on staging: map one SKU in two branches, dispatch a small quantity, record
one partial receipt and a discrepancy, inspect both stock ledgers and print views.
Check destination-only manager access and cashier denial. Assign appropriate
staff, and establish a consistent SKU/unit convention before rollout.

**Rollback deletes transfer manifests, receipts, discrepancies, and ledger links;
it does not restore source stock or remove destination stock.** It is not a safe
way to undo a dispatch. Never roll back a live installation without a verified
backup and an explicit stock/history recovery plan.

## Verification

Feature tests cover authorization, endpoint scoping, original product snapshots,
source deductions, partial/full receiving, all-loss completion, unit conservation,
idempotency, changed-payload rejection, stale forms, duplicate/foreign/negative
lines, stock limits, archive guards, date filters, and permission revocation.
Migration tests preserve pre-existing purchase documents and stock history.

Eight native MySQL parallel tests cover source overselling, duplicate dispatch,
opposite-direction dispatch, duplicate receipts, over-receiving, stale partial
receipts, competing receipts at the stock cap, and receiving versus checkout.
SQLite deliberately skips native concurrency tests. Browser tests exercise the
real dispatch/partial receiving/discrepancy workflow, mobile controls, both print
views, ledger balances, and cashier denial alongside previous phases.
