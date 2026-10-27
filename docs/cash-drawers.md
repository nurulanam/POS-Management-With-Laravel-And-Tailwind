# Cash drawers and reconciliation

## Rollout: opt in per branch

Cash tracking is **off by default**, including for existing branches. Existing
sales and refunds remain unchanged with a NULL till association. No opening
balances or historical cash movements are invented by the migration.

1. Back up your database, preserve `.env` and `APP_KEY`, install the locked
   dependencies, and run `php artisan migrate --force`. The new migration is
   `2026_10_27_000005_add_cash_drawer_sessions.php`.
2. Run `npm ci && npm run build` and clear/rebuild framework caches. Verify on
   staging with your production database engine before rollout.
3. Coordinate a quiet cutoff with staff. As a manager/admin, select the branch,
   open **Cash drawers**, acknowledge the change, and **Enable cash tracking**.
4. Each operator counts their own opening float and opens a till. Reload any
   existing checkout/refund forms so they reference that till.

Enabling is recorded with actor and UTC timestamp. There is no disable action:
turning tracking off would allow new cash to bypass reconciliation. Forms opened
before activation cannot create new untracked sales or refunds after activation.
An exact retry of an already-completed historical transaction can still retrieve
its original receipt without recording it again.

Do not roll back the migration on a live business database without a verified
backup/recovery plan. Rollback removes cash-session history, entries, tracking
settings, and sale/refund till associations; it does not reverse physical cash.

## What a till means in this phase

A till is a **personal cash session for one user in one branch**, not a named
hardware register. One user may have at most one open till per branch. Different
operators have separate tills and must keep their physical cash separate. There
is no shared drawer, session reassignment, or automatic transfer between tills.
A user working at multiple branches may have one till in each; changing branch
context does not move cash or close another branch's till.

Opening float is entered explicitly, including zero. Closing does not automatically
withdraw cash, deposit it in a safe, or carry it to the next session. Physically
secure/hand over cash according to your procedures; explicitly count the next
opening float. A session can span midnight and is not auto-closed at day end.

## Permissions

| Action | Cashier | Manager / admin |
| --- | --- | --- |
| Enable tracking | No | Accessible current branch |
| Open a till | Own till | Own till |
| Complete cash sale | Own open till | Own open till |
| Record refund | No | Own open till, regardless of original cashier |
| View till history and reconciliation | Own sessions only | All sessions in accessible current branch |
| Record cash in/out | No | Any open till in accessible current branch |
| Count and close | Own open till | Any open till in accessible current branch |
| Review a variance | No | Closed, unreviewed variance in accessible current branch |

Managers sign in as themselves. No delegated PIN or impersonation is used.
A manager may review their own variance; this is an audited acknowledgment, not
independent dual approval. Managers can close a deactivated employee's till.
Branches cannot be deactivated until all their open tills are closed. Historical
closed records remain available after reactivating/selecting an archived branch.

## Cash accounting

`expected = opening float + sales retained − refunds paid + cash in − cash out`

- Sales retain their final total after discount, including tax. **Tendered cash
  minus change**, not the tendered amount alone, enters the drawer balance.
- Refunds leave the **paying manager's current till**. The original sale may be
  from a different employee, a closed till, an earlier day, or before tracking
  was enabled. The original till and its closed reconciliation are unchanged.
- Zero-value sales/returns still produce a zero cash entry and advance the till's
  activity version so stale closing forms cannot overlook those transactions.
- Manual cash-in/out requires a positive amount, a reason, and physical-movement
  confirmation. Managers can record funding, safe drops, or petty-cash payouts.
  Do not duplicate sales/refunds here. Cash-in/out is **not** an accounting income,
  expense, bank integration, or linked inter-till transfer workflow.
- Expected cash cannot go negative. A refund or payout that cannot be covered is
  rejected atomically: no partial refund, stock update, or ledger entry remains.
  Record actual physical funding before retrying; do not invent a cash-in entry.
- Cash values use integer minor units (whole yen for JPY; two decimals for other
  supported currencies). Opening/count amounts, individual manual movements, and
  the resulting expected balance are limited to 2,000,000,000,000 minor units.
  A sale exceeding the remaining balance capacity is rejected; use an actual
  cash drop or close/open procedure rather than changing the original sale.
- Currency cannot change once any till exists, even with no products or sales.

The cash ledger is append-only. It stores source sale/refund IDs, actor/name,
reason, signed amount, balance after the movement, and UTC time. No UI or route
edits/deletes entries. Opening float is shown separately in the summary rather
than as an additional movement.

## Closing and reviewing

Stop transactions, open the session, and count the physical cash. Enter the
count and a closing note (including discrepancy explanation where applicable),
then explicitly confirm. The browser previews over/short with integer arithmetic;
the server calculates the authoritative amounts.

`variance = counted cash − expected cash`

A positive variance means over; a negative variance means short. Closing freezes
the count, expected balance, variance, closer/name, note, and timestamp. It cannot
be edited, reopened, or replaced with a corrected count. Review before submitting.
The original closing note and a later manager review preserve the audit trail if
an investigation discovers an error; review does not change recorded amounts.

A nonzero variance is marked **Awaiting variance review**. A manager/admin records
findings and explicitly acknowledges the discrepancy. A single immutable review
records reviewer/name, note, and timestamp. A balanced till needs no variance
review. Review does not erase the shortage/overage or create a cash adjustment.

## History and daily operation

**Cash drawers** filters by open/closed/pending-review/reviewed status and by
**opened date or closed date**. For day-end reconciliation, select **Closed date**
and that day's branch-local date range. Closed-date ranges exclude still-open
tills; review the **Open** filter separately for unfinished sessions.

History date boundaries use the current branch timezone. Each session's detail
and printable summary retain its opening-time timezone, currency, branch name,
and owner name. The summary includes all session activity, even across midnight;
it is a till reconciliation, not a calendar-day revenue report. The paginated
activity ledger is screen-only and is not silently printed as a complete ledger.
Open-session printouts are explicitly provisional. Closed summaries are final,
with any subsequent variance review appended without changing financial amounts.

The sales dashboard continues to report sales/refund **activity**, not cash on
hand. Historical/untracked transactions must not be confused with reconciled
sessions. Sales-history totals can also differ from till totals because refunds
use the till that actually paid out, rather than modifying the original sale's till.

## Transaction, replay, and concurrency safety

All actions lock the branch first, refresh actor access, then lock the relevant
session/records. Sales/refunds and their cash entry commit in the same transaction
as stock and receipt records. Closing and payments cannot pass each other without
one being recorded first or rejected. If activity changes after the closing form
was loaded, the version check rejects that count: reload, inspect activity, and
count again. Counts are deliberately **not restored** into a refreshed closing
form. Cash movements also require the current version, including zero-cash events.

Opening, movements, closing, and reviewing have actor-bound UUID/hash replay
protection and database uniqueness constraints. Same-payload retries retrieve the
existing record; changed payloads conflict. One active till per owner/branch is
also database-constrained. Payment retries preserve original till association,
including after closing; retrying must not create cash in a replacement till.
After any network error, verify session history before physically paying again.

No transaction-reversal mechanism, cash denominations, blind counts, shared
registers, dual-control approvals, automated bank deposits, or drawer hardware
integration is included in this phase.

## Verification

Feature tests cover enablement, role/branch access, actor-bound replay, open/close
lifecycle, stale forms, tax/change accounting, historical refunds, distinct paying
tills, zero-cash events, limits/atomic rollback, variance reviews, JPY, currency
locking, branch deactivation, and overnight local-date filters. Playwright covers
activation through a sale/refund, cash-out, closing shortage, variance review,
mobile layout, print styling, and closed-till checkout blocking.

Seven new real MySQL/pcntl races cover competing/duplicate opens, duplicate cash
movements, competing/duplicate closes, and checkout/refund versus closing. These
join the six existing checkout/refund races. They intentionally skip on SQLite;
the MySQL CI job rejects skipped tests.
