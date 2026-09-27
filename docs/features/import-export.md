# Import / Export

**Workspace > Import / Export** has a tab each for products, orders and users.
Exporting needs `export_data` (users also need `view_users`). Importing needs
`import_data`; orders also need `create_orders`, and users also need
`create_users`.

Files can be CSV, XLSX or XLS, up to 10 MB. Product and order files over
`IMPORT_SYNC_MAX_KB` (512 KB by default) are processed on the queue, and you
get a notification when they finish. Exports over `exports.sync_row_limit`
rows are prepared in the background and listed under "Your exports".

Every export neutralises cells that a spreadsheet would run as a formula (a
leading `=`, `+`, `-`, `@`, tab or CR gets a `'` in front). Imports strip those
characters from free text.

## Import results

A clean import shows a success message. If any row had an error or a warning,
the page shows the counts and lists each problem by row number. Warnings are
things that did not stop the import but that you should know about, such as a
duplicate SKU that was skipped or an unknown supplier. Queued imports put the
same counts, plus the first 50 errors and warnings, on the completion
notification.

## Products

One row per product, matched by `sku`. Required: `name`, `sku`, `price`,
`stock`. Download the template for the full column list.

### Prices in other currencies

Each additional currency gets its own column: `price_EUR`, `price_GBP`, and so
on (any code from `config/currencies.php`). The export and template include a
column for every currency the organization uses: those in its
`supported_currencies` plus any a product already has a price in.

On import:

- a number sets the price in that currency (it must be 0 or more),
- a blank cell removes that currency's price,
- a currency with no column in the file is left unchanged,
- a column for an unsupported currency is ignored with a warning.

## Orders

### Export

- **One row per order**: the order, its customer and totals.
- **One row per order line**: every line with its order's details.

Line-items columns: `Order Number`, `External Reference`, `Order Date`,
`Status`, `Customer Name`, `Customer Email`, `Currency`, `Line`, `Product SKU`,
`Product Name`, `Variant SKU`, `Variant Title`, `Quantity`, `Unit Price`,
`Line Tax`, `Line Total`, `Order Subtotal`, `Order Tax`, `Order Shipping`,
`Order Total`, `Notes`.

The order fields and totals are **repeated on every line**, so each row stands
on its own when sorted, filtered or pivoted. To add up order totals, count
each order once, for example only rows where `Line` is 1. `Variant SKU` and
`Variant Title` are filled only for lines sold as a variant. Both modes take
the same status and created-date filters.

### Import

Use this to bring orders over from another system. The format is one row per
order line. The column names match the line-items export.

| Column | Required | Notes |
|---|---|---|
| `external_reference` | yes | The order's ID in the old system. Rows that share it make up one order. |
| `order_date` | yes | Any date format, or a spreadsheet date. |
| `status` | no | `pending` (default), `processing`, `shipped`, `delivered`, `cancelled`. |
| `customer_name`, `customer_email` | no | See customers below. |
| `product_sku` / `variant_sku` | one of them | A variant SKU is also accepted in `product_sku`. Products sold by variant need a variant SKU. |
| `quantity` | yes | Whole number, 1 or more. |
| `unit_price` | no | Blank uses the variant's or product's current price. |
| `line_tax` | no | Tax on this line. |
| `order_tax`, `order_shipping` | no | Order-level amounts. |
| `currency`, `shipped_at`, `delivered_at`, `notes` | no | |

Order-level columns (date, status, customer, order tax and shipping, currency,
dates, notes) come from the first non-blank value among the order's rows. You
can repeat them on every line or fill them in on one line only.

How it runs:

1. **Everything is checked first.** Every row is validated and every SKU is
   looked up before any order is created. Each problem is reported with its
   row number.
2. **All or nothing per order.** If any row of an order is invalid, or the
   order cannot be created (for example, not enough stock), none of it is
   kept. That includes a customer it would have created and any stock it would
   have used. Other orders in the file are not affected.
3. **Duplicates are skipped.** The reference is stored on the order as
   `external_reference`, which is unique per organization. If a reference has
   already been imported, that order is skipped with a warning, so importing
   the same file again never creates duplicates. Retrying a queued import that
   failed partway through is safe for the same reason.
4. **Orders are created like hand-entered ones** (through `OrderService`):
   stock goes down, location bins are drawn from, serial and batch units are
   allocated, and variant stock is used. An order without enough stock is
   reported and not imported.

**Customers** are matched to an existing customer by email (case-insensitive).
If there is no match, a customer is created, named after `customer_name` (or
the email if no name is given). An order with neither a name nor an email is
imported without a linked customer.

**Historical import (don't adjust stock).** Tick this for past orders whose
goods have already left, so your current stock figures already account for
them. The orders and their lines are recorded with their prices, dates and
statuses, but inventory is not touched at all: there is no stock check, no
stock reduction, no bin movement, no stock-adjustment entries and no serial
allocation. **Cancelled** orders never change stock in either mode.

Imported orders get `source = import`. They don't send a "new order" alert for
each order; you get one summary when the import finishes.

**Webhooks and plugins.** The `order_created` action drives both the
`order.created` webhook and plugin order hooks.

- **Historical imports never fire it.** These orders are records of sales
  that already happened. Announcing them as new would make a connected
  fulfilment, accounting or shop system act on them again.
- **Stock-adjusting imports fire it for each order by default**, because they
  behave like hand-entered orders: stock moves, and integrations that follow
  orders or stock should hear about it. Untick "Notify webhooks and plugins
  about each order" (`notify_integrations=0`) to skip it, for example when the
  orders already exist in the connected systems. Queued imports keep this
  setting.

## Users

The export includes name, email, custom roles, status, last sign-in and base
role. It never includes passwords.

Import columns: `name`, `email`, `role` (base role: `member`, `manager` or
`admin`; blank means `member`), and `roles` (custom role names separated by `;`
or `,`). A user export can be imported again as it is.

- **Passwords never go in the file.** A `password` column is ignored, with a
  warning. Each new user gets a random password that nobody knows. By default
  they are emailed a link to set their own password. The link is a standard
  password-reset link and expires after `auth.passwords.users.expire` minutes.
  After that, they can use "Forgot your password?" to get a new one. If you
  turn off invitations, the accounts are still created and the invitation
  stays pending until the user requests a reset link.
- **Privilege escalation guard.** Each row goes through the same check as the
  user form (`RoleAssignmentGuard`). An importer who is not an admin can only
  create members, and can only give them roles whose permissions they hold
  themselves. They can never give out the administrator or manager system
  roles. A row that breaks these rules is rejected.
- Roles are matched by name or slug among your organization's roles and the
  system roles. Another organization's roles never match.
- An email that already has an account, or that appears earlier in the file,
  is skipped with a warning.
- **Admin alerts.** Admins who have user activity alerts turned on get one
  "N users imported" email for the whole import. It lists the accounts (up to
  50) and says how many have admin access. They do not get a separate "new
  user" email per row. The security log still has a `user.created` entry for
  every account.
