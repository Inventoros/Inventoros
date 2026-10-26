# Stock audit count history

Each accepted count or recount appends evidence in the same transaction that updates the audit line. The evidence retains the system quantity at counting time, counted quantity, variance, notes, author name and timestamp. Failed, stale or unauthorized submissions do not append evidence. Completion still books only the latest accepted count.

Open **View count history** under a counted line to see its saved counts, newest first, twenty per page. The page uses the audit's existing organization, warehouse and view permissions. History is loaded on demand and is not included in the main audit's item payload. Archived product, variant and location names remain available on the history page. The ordinary application has no update or delete endpoint for saved evidence; deleting an organization or permanently removing its owning audit records also removes their dependent history.

The upgrade preserves each existing line's latest saved count as **Earlier saved count**. Earlier overwritten values cannot be recovered, and no earlier attempts are invented. New accepted counts use increasing sequence numbers, so identical recounts within one second still have distinct concurrency revisions. Existing revision tokens remain valid until a new count is accepted.

When a saved response is lost, retrying an older draft can require reviewing the latest count. Matching quantities and notes alone do not acknowledge a draft, because another operator may have saved those same values. The draft stays available until explicitly reviewed or discarded.
