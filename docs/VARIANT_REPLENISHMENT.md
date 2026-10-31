# Variant replenishment by warehouse

Open **Products → Variant replenishment**. Add one rule for an active product
variant and warehouse, select its receiving location and supplier, then enter
the supplier-currency unit cost, reorder point and target stock. Targets apply
to warehouse physical on-hand stock. Organization-wide reservations are not
allocated to a warehouse by this planner.

When `on hand + incoming <= reorder point`, the suggested quantity fills the
gap to target stock, subject to the minimum order quantity. An optional order
multiple rounds that quantity up to a full pack. For example, 2 on hand, a
target of 10 and a pack size of 6 suggests 12 units. No order is suggested when
the stock position is above the reorder point.

Incoming means unreceived units for that exact variant and warehouse on
non-deleted draft, sent and partially received POs. Rejected and cancelled
orders do not count. Existing manual PO lines without a receiving destination
use their product's primary location. Goods already received count in on-hand
stock, so they are not counted twice.

**Create draft PO** rechecks current quantities while holding database locks.
Each rule produces one draft PO with its exact variant, cost, currency and
receiving location. Repeated calls see existing inbound stock. Nothing is
sent to suppliers, approved, paid or received automatically. Normal PO review,
approval and receiving still apply. Receiving credits the selected variant's
bin in the destination warehouse and enforces the receiver's warehouse access.
Deleting a rule preserves existing purchase orders and their destinations.

Automatic draft creation is disabled by default. Enabling it requires product
editing and purchase-order creation permission. The responsible user is the
person who last saved the rule. Each automatic run rechecks that person's
active organization membership, permissions and warehouse access. Unavailable
products, variants, suppliers, destinations or responsible users are skipped;
changing a supplier's currency requires reviewing the rule's cost. No exchange
rate is inferred.

With Laravel's scheduler enabled, `inventory:replenish-variants` runs daily at
06:10 in the application's scheduler timezone. It also supports
`--organization=<id>`. It estimates demand in batches of 100 policies, then
rechecks each due rule transactionally. A command-wide cache lock prevents
overlapping scheduled runs; a rule row lock serializes manual and automatic
draft creation. No existing aggregate product reorder behavior is changed.

Read access requires `view_products`; rule editing requires `edit_products`;
draft creation requires `create_purchase_orders`. REST endpoints under
`/api/v1/variant-replenishment` enforce the corresponding token abilities too.
See [the API specification](api/openapi.yaml). Rule lists paginate 20 rows and
form searches are bounded; the whole product catalogue is never sent to the
browser. Accepted changes and generated orders are recorded in the activity log.
