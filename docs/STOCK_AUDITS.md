# Stock audit counts and interrupted connections

Start the audit while connected, then keep its page open while counting. When
you edit a quantity or note, Inventoros keeps a draft in the current browser tab.
Use **Close and keep draft** to move to another line. Draft quantities are shown
separately from the counts confirmed by the server.

If the connection drops, continue entering counts on the loaded page. Once
connected, use **Send saved counts**. Drafts survive a refresh in the same tab;
they are removed only after the server confirms the count or you explicitly
discard them. Closing the tab ends its draft storage. The page warns before
leaving with unsent drafts. If browser storage is unavailable or full, a warning
explains that those drafts exist only in memory and the page must stay open.

If someone changes a saved count while you are working, sending an older draft
does not replace it. Open **Review draft**, compare the latest count and notes,
then explicitly confirm that you reviewed them before saving your own count.
A failed or unconfirmed request retains its draft. Retrying a request whose
response was lost recognizes matching server evidence without recording it again.

Send or discard every draft before completing the audit in that tab. Other
devices cannot see drafts that have not reached the server; coordinate with
other counters before completing a shared audit.

Drafts are separate for each user, organization and audit. They contain only the
line ID, entered quantity, note and concurrency token. Inventoros does not cache
the catalogue or authenticated pages for offline use. Loading a page, barcode
lookup, starting an audit and completing it still require a connection. There
is no automatic background synchronization.

For integrations, both the REST and web count endpoints return `count_revision`
on an audit item. Send it as `expected_revision` when recording a count to
receive HTTP 409 (`stale_count`) if the evidence changed. The optional sixth
`StockAuditService::recordCount()` argument provides the same check to plugins.
Omitting it preserves the existing unconditional write behavior.
