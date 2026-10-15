# Chargebee Tools

Bulk Chargebee jobs that the Chargebee web interface can't do in bulk, run from a CSV of
subscription, customer or order IDs. It's at **https://kb.faithcatholic.com/admin/chargebee-tools**
for roles with the **Use the Chargebee bulk tools** permission. That permission can bill
customers on the live site, so grant it carefully.

It replaces the scripts in the old `sites/chargebee` folder:

| Operation | Was | CSV of | What it does |
|---|---|---|---|
| Charge future renewals | `future_renewal.php` | subscription IDs | Creates the advance invoice for the next term(s) now |
| Change renewal date | `change_renewal_date.php` | subscription IDs, optional date | Moves the term end (next billing date); no proration by default |
| Set auto collection | `auto_collect_on.php`, `auto_collect_off.php` | subscription IDs | Turns automatic payment collection on or off |
| Mark orders shipped | `ship-orders.php` | order IDs | Sets each order to shipped |
| Look up order status | `check-order.php` | order IDs | Reports each order's status (read only) |
| Check billing addresses | `check_billing.php` | customer IDs | Flags incomplete billing addresses (read only) |

## Using it

1. On the overview page, choose **New run** next to an operation.
2. Choose the **Test** or **Live** Chargebee site, and for operations that change things, the
   mode:
   - **Check only** looks up every row in Chargebee and changes nothing. Use it first: it
     finds bad IDs and cancelled subscriptions, and for date changes it shows the new date.
   - **Make the changes** does the job.
3. Fill in the options, upload the CSV and choose **Preview**. The CSV needs only IDs in the
   first column, with no header unless you tick the header box. Dates are UTC, such as
   `2026-11-01 07:00`, or Unix timestamps like the old scripts used.
4. The preview lists the rows to send and any rows it will skip (bad IDs, past dates,
   repeated IDs). Making changes on the live site needs **LIVE** typed to confirm.
5. **Queue run.** It starts within five minutes and sends one row per second, so 3,000 rows
   take about 50 minutes. You can close the browser. The run page reloads every 30 seconds
   while it runs. It shows a progress bar split into succeeded (green), succeeded with a
   warning (amber) and failed (red), with a counter for each. The runs list shows
   "sent / total" and the number failed.
6. **Download results CSV** gives every row with its status, message and result values such
   as the invoice ID. The page lists the errors, warnings and skipped rows.

**Cancel** stops the rows not yet sent. Rows already sent keep their results.

## How it works

- Each run and each CSV row is stored in the `chargebee_tools_run` and `chargebee_tools_row`
  tables. Pending rows are the queue.
- `drush chargebee:process` sends pending rows, oldest run first, until the queue is empty
  or its time limit is up. Upsun cron runs it every five minutes with a 270-second limit
  (`.upsun/config.yaml`), and a lock stops two from running at once. Locally, run it
  yourself: `ddev drush chargebee:process`.
- Rows are never sent twice. Each row is marked as processing before its call, so if a
  deploy or crash kills the process mid-call, the next run marks that row as an error
  ("check this one in Chargebee") instead of sending it again. Only rows Chargebee
  rejected for its rate limit are retried, since those were never applied.
- A warning means the call worked but the row needs a look: a cancelled or paused
  subscription, a renewal charged without an invoice, or an incomplete billing address.
- The module calls the Chargebee REST API (v2) directly with Drupal's HTTP client, so there's
  no Chargebee SDK dependency.

## Setup

1. **Store the API keys as sensitive variables on the kb project** (not in git or config):

   ```bash
   upsun variable:create -p q3r62pwvyzjca --level=project \
     --name=env:CHARGEBEE_LIVE_API_KEY --sensitive=true \
     --visible-build=false --visible-runtime=true --value='KEY'
   upsun variable:create -p q3r62pwvyzjca --level=project \
     --name=env:CHARGEBEE_TEST_API_KEY --sensitive=true \
     --visible-build=false --visible-runtime=true --value='KEY'
   ```

   `settings.php` reads them into `$settings['chargebee_tools']`, with the site names
   `faithcatholic` and `faithcatholic-test`. A site without a key isn't offered. To keep
   the live site off the sandbox environment, set `CHARGEBEE_LIVE_API_KEY` to an empty
   value at the environment level there.
2. **Enable the module and grant the permission**, then export config:

   ```bash
   drush en chargebee_tools
   drush role:perm:add ROLE 'use chargebee tools'   # administrators already have every permission
   drush cex
   ```

For local work, put `CHARGEBEE_TEST_API_KEY` in `.ddev/.env` and leave the live key out.

## Adding an operation

Add a class to `src/Plugin/ChargebeeOperation/` that extends `ChargebeeOperationBase`, with
a `#[ChargebeeOperation]` attribute giving its label, description, `id_type` (subscription,
customer or order) and `writes: TRUE` if it changes Chargebee. Then clear caches. It shows
up on the overview page automatically.

The minimum is `process()`, which makes the API call for one row and returns
`['message' => ..., 'data' => [...], 'warning' => bool]`. `ShipOrders` is the smallest
example. Override these as needed:

- `optionsForm()`, `validateOptions()`, `optionsSummary()` and `defaultOptions()` for
  settings that apply to every row (see `ChangeTermEnd`).
- `columns()` for extra CSV columns, and `validateRow()` to skip bad rows before anything
  is sent.
- `resultColumns()` for extra columns in the results CSV.
- `check()` for what **Check only** does. By default it looks up the row's ID.

`$this->client->get()` and `->post()` take the site, a path built with
`ChargebeeClient::path()`, and Chargebee's parameter names in snake_case. Nested parameters
use Chargebee's form keys, such as `subscription_items[item_price_id][0]`. The
[Chargebee API reference](https://apidocs.chargebee.com/docs/api) lists the endpoints.
