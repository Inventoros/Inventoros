# Importing external payment records

PHP integrations can call `OrderPaymentService::recordImported($order, $actor,
$data, announce: false)` to record money that was already processed elsewhere.
This method does not charge a customer or send a refund. It has no web or REST
endpoint. Existing manual payment endpoints keep their normal rules.

Supply `source`, `type` (`payment` or `refund`), a positive two-decimal `amount`,
a supported `method`, a stable provider-prefixed `reference`, and the actual
`paid_at` timestamp. `notes` is optional. The integration must authorize the
caller, verify the provider response and ensure the amount uses the order's
currency. The actor must belong to the order's organization.

An order row lock serializes imports and balance calculation. A reference is
unique within the order and compared case-sensitively on every database driver.
Replays return the existing row, including manually voided rows, and never
resurrect a void. Reusing a reference with a different amount, type or method is
refused. Integrations should also maintain provider mappings when a reference is
globally unique across orders.

Historical receipts can predate cancellation and can exceed the current order
total; their actual amounts are retained and reported as overpayments. Refunds
cannot exceed recorded net receipts. Rows remain append-only and correctable
through the existing void workflow. The service derives payment state and writes
an activity entry containing the source and acting user.

Imports do not announce payment hooks by default. An integration can opt into
`payment_recorded` for current operational payments with `announce: true`.
Idempotent replays never announce again. Historical backfills should remain
silent. Import captures/receipts before their refunds, and reconcile existing
manual ledger entries before enabling automatic imports.
