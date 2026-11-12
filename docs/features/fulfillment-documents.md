# Pick lists and packing slips

These documents are included in Inventoros core and require no paid plugin.

## Pick list

Open a pending or processing order and choose **Pick list**. The PDF opens in a
new tab for printing. It lists the stored item names, SKUs and quantities that
have not yet physically shipped, with checkboxes and space for picker/checker
names. Variant SKUs distinguish variants even after product details change.

The order must have committed stock and have approval granted or not required.
Historical orders imported without stock movement, cancelled orders and orders
already marked shipped or delivered cannot generate new pick lists. Quantities
in shipped, in-transit, delivered or exception shipments are excluded; pending
and labelled parcels still need picking. Printing does not confirm a pick or
change inventory.

The list is a simple order checklist. It does not specify reserved source bins
or optimize a walking route.

## Packing slip

On the order's **Shipments** card, choose **Packing slip** for the parcel you are
packing. This also works for manual shipments without a purchased carrier label.
Create the shipment first so the document can list the quantities in that
specific parcel, including partial shipments.

The slip uses the shipment's saved recipient address and exact item quantities.
It includes the order and shipment references, plus carrier and tracking when
available. Prices, costs, payments, private notes and customer email/phone are
excluded. You can reprint a slip after dispatch; cancelled parcels have no slip.

## Access and printing

Pick lists need **View Orders**. Packing slips need **View Orders** and **View
Shipments**. Warehouse assignments apply: the order warehouse must be accessible,
and a packing slip also requires access to the parcel's ship-from warehouse.

Both PDFs use A4 paper with repeated table headings and page numbers. Printing is
read-only: stock, order/shipment status, invoice numbers and emails do not change.
