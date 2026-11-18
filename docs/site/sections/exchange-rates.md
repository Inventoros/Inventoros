Purchase orders can be placed in a supplier's own currency. Inventoros converts them to your organization's currency at an exchange rate it stores on each purchase order, so totals, approvals and received costs are in your currency, and the supplier still gets a purchase order in theirs.

## How it works

Your organization's currency is the one set in **Settings > Organization** (regional settings). A purchase order whose currency differs from it (for example a EUR order in a HUF organization) gets an exchange rate:

1. When you pick the supplier, the order form shows the rate for the order date: `1 EUR = 364.35 HUF`, where it came from and the date it applies to, and the totals in your currency.
2. The rate is saved with the draft. It follows the draft while you edit it: changing the currency or the order date picks up the rate for the new values.
3. When the purchase order leaves draft (emailed to the supplier, or marked as sent), the rate is locked. Later rate updates, synchronizations and edits never change it.
4. Receiving books each line's cost in your currency at the locked rate (see the supplier price history on the product page, which shows both amounts).

Purchase orders in your own currency need no rate and behave exactly as before.

Tax is part of the converted totals. When purchase order lines have tax rates, the order form, the purchase order page and the PDF show each line's gross and the tax for each rate in your currency as well, and the converted tax and total include the line taxes. Unit costs are net (before tax), so receiving books the net cost.

The rate is `1 <PO currency> = <rate> <organization currency>`, stored with up to 10 decimals. Converted amounts use exact decimal arithmetic and are rounded half up to the currency's minor unit (two decimals for most currencies, none for JPY and KRW).

## Providers

| Provider | What it is | Shared between organizations |
|---|---|---|
| Frankfurter (European Central Bank) | The ECB's daily reference rates through the public Frankfurter API. No account or key. The default. | Yes |
| Manual rates | Rates your administrators enter. Never contacts anything. | No |
| Plugin providers | Added by plugins (see Plugin Development, "Exchange rate providers"). | Declared by the plugin |

The ECB publishes rates on TARGET working days around 16:00 CET. On a weekend or holiday the most recent published rate is used, and its own date (the effective date) is stored and shown, never the date you asked for.

Rates from a shareable provider are stored once for every organization on the installation, so many organizations needing EUR to HUF cause one request. A provider whose data is licensed per customer (a paid API key) is not shareable: its rates are stored per organization and never shown to another one. Manual rates always belong to one organization.

## Setting it up

Open **Settings > Exchange rates**. You need the `manage_exchange_rates` permission (administrators have it).

- **Default provider**: where rates come from. Inventoros never switches provider on its own.
- **Fallback provider** (optional): used only when the default provider fails, and always labelled as the fallback on the order and in the snapshot. Choosing **Manual rates** as the fallback lets an entered rate stand in when the main provider is down.
- **Provider settings**: some plugin providers need an API key or account. Keys are encrypted at rest and never shown again; leave the field blank to keep the stored value.
- **Treat rates as stale after (days)**: a stored rate older than this (default 4 days, to cover weekends and holidays) is shown with a **Stale** marker and is not applied on its own.
- **Currencies**: rates are kept for every currency your active suppliers use, plus any you tick here.
- **Synchronize rates automatically** and **Frequency** (every hour, every 6 hours, daily, weekly). Off by default; rates are still looked up when a purchase order needs one.
- **Refresh now** fetches the latest rates at once and shows the outcome.

The page also lists the newest stored rate per currency with its effective date and age, the last synchronization result, the next attempt and the last error.

### Manual rates

Under **Manual rates**, enter a rate for a currency and date. These are used when **Manual rates** is the default or the fallback provider. Deleting a manual rate does not change purchase orders that used it.

## Freshness, failures and fallbacks

Inventoros never substitutes a rate silently:

- A stored rate within the stale limit is used, with its effective date shown.
- If the provider fails and the newest stored rate from that same provider is older than the limit, it is shown as **Stale**. A user with `override_purchase_order_exchange_rate` can accept it (**Use this rate**) or enter one; nobody else's order uses it.
- If a fallback provider is configured, its rate is used and marked as coming from the fallback.
- If there is no rate at all, the purchase order is still saved, without one. A missing rate never blocks saving a draft or any other inventory operation.

After a failed request the provider is not asked again for a few minutes (`EXCHANGE_RATES_FAILURE_COOLDOWN`, default 300 seconds), so a slow or unreachable provider never holds up pages.

## Entering a rate on a purchase order

Users with `override_purchase_order_exchange_rate` (administrators, and the Purchasing Agent permission set) can, on the order form:

- **Enter rate manually**: type the rate. The order records that it was entered by hand and by whom.
- **Use this rate**: accept a stale provider rate. The order records the provider, its date, and who confirmed it.
- **Use the provider rate**: go back to the provider's rate.

Over the REST and GraphQL APIs the same choices are `exchange_rate_mode` (`auto`, `manual`, `confirm`) and `exchange_rate`; the API token must also allow `override_purchase_order_exchange_rate`.

## Approvals

When purchase order approvals are on, the threshold is compared with the total in your organization's currency, including tax and shipping (whether the tax comes from line tax rates or is entered for the whole purchase order). A purchase order in another currency with no rate recorded cannot be compared, so it needs approval. Neither can one whose rate was entered by hand, so it needs approval too, whatever its converted total. Changing the rate of an approved draft sends it back for approval, like changing its total.

## Older purchase orders

Purchase orders created before exchange rates existed keep working:

- In your organization's currency: unchanged.
- In another currency: shown as **No exchange rate recorded**. Nothing is invented. Received costs are logged in the supplier's currency, labelled with it. While such an order is sent or partly received, a user with `override_purchase_order_exchange_rate` can record a rate on it once (it locks immediately) so the remaining receipts are converted.

## The purchase order document

The PDF sent to the supplier is in the purchase order currency. For a foreign-currency order it also shows the rate, its source and date, the tax per rate (when lines have rates) and the total in your currency, or that no rate was recorded.

## Synchronization schedule

The scheduler runs `exchange-rates:sync` hourly. It queues one job for every organization whose automatic synchronization is due and makes each distinct request once (one per provider and foreign currency for shareable providers). A rate limit (HTTP 429, honouring `Retry-After`) or an outage stops further requests to that provider for the run and retries with back-off (15 minutes, 1 hour, 4 hours, then the regular frequency). An unsupported currency or a malformed response is recorded and not retried early.

```bash
php artisan exchange-rates:sync              # queue the organizations that are due
php artisan exchange-rates:sync --now        # run in this process
php artisan exchange-rates:sync --org=3 --force --now
```

Make sure the scheduler and a queue worker are running (see the installation guides).

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `EXCHANGE_RATES_DEFAULT_PROVIDER` | `frankfurter` | Provider for organizations that have not chosen one |
| `EXCHANGE_RATES_STALE_AFTER_DAYS` | `4` | Default stale limit for new organizations |
| `EXCHANGE_RATES_REFRESH_AFTER_MINUTES` | `360` | How long today's newest rate is trusted before asking again |
| `EXCHANGE_RATES_FAILURE_COOLDOWN` | `300` | Seconds to wait after a failed request |
| `FRANKFURTER_URL` | `https://api.frankfurter.dev/v1` | Point at a self-hosted Frankfurter instance |
| `FRANKFURTER_TIMEOUT` / `FRANKFURTER_CONNECT_TIMEOUT` | `8` / `4` | Request timeouts in seconds |

Only currency codes are sent to Frankfurter; no organization data leaves the server.
