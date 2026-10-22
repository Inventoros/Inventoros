# Administrator stock reset

Administrators can use **Products > Reset stock to zero** for the active organization. Review the counts, enter a reason, the current password and `ZERO STOCK`. The preview expires after ten minutes and any stock change requires a fresh preview. This affects the entire organization, including inactive and soft-deleted products, and retains catalog records and transaction history. Product and variant totals and bins are corrected through the stock ledger; available serials are retained as written off and batch balances are zeroed, with audit records. Existing sold, consumed and transferred serials remain unchanged.

Finish or cancel open transfers, in-progress stock counts and production, and release active reservations before resetting. This action does not disable integrations: pause external stock imports before resetting if they could restore quantities. No database tables are truncated.
