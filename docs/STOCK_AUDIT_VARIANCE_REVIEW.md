# Stock audit variance review

Select **Require variance approval** when creating or editing a draft audit. Existing audits retain their current behavior. Once an audit starts, its review requirement cannot be changed.

Saving a nonzero variance puts that count in **Awaiting review**. A reviewer needs both `view_stock_audits` and `approve_stock_adjustments`, plus access to the audit's warehouse. Approval records a decision against the exact saved count revision. It creates no stock adjustment. Completing the audit still books the latest counted variances through the normal stock ledger.

A reviewer can instead request a recount with a reason. The earlier quantity, notes, author and count history remain available. The request changes the count revision so an older offline draft cannot silently satisfy it. A fresh count resolves the recount request; any remaining variance needs a new approval. Every new count invalidates the previous approval, including identical counts recorded in the same second.

An audit cannot finish while a variance awaits approval or a recount remains requested, including when completing with uncounted lines. A balanced fresh count needs no approval. A decision is recorded once per saved count, under the audit and item locks. Count history displays the review decision, reviewer and notes beside its original count.

Reviewers cannot approve their own counts. Administrators can do so only when the organization's existing `approvals.admins_can_self_approve` setting permits it. The web and REST endpoints call the same service. Scoped REST tokens must include both review permissions.

REST: `POST /api/v1/stock-audits/{audit}/items/{item}/review`, with `decision` (`approved` or `recount_requested`), `expected_revision`, and optional `notes` (a reason is required for recounts). Stale or already-decided counts return 409; invalid review states return 422. `StockAuditReviewService::decide()` provides the same guarded operation to core PHP callers.
