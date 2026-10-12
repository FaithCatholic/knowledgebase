# Upsun Dashboard

Shows every Upsun project we host on one page at **https://kb.faithcatholic.com/hosting/dashboard**,
visible to roles with the **View the Upsun hosting dashboard** permission. The page
includes hosting costs, so grant it accordingly.

For each project's production environment it shows:

| Column     | Source |
|------------|--------|
| Project    | Org project list: title, org, plan, region, link to the Upsun console |
| Production | Environment status and the date of the deployed commit |
| Drupal     | `drupal/core` and `drush/drush` versions, read from `composer.lock` in the deployed commit through the Upsun Git API |
| PHP        | The app's runtime in the current deployment (`php:8.4`) |
| Services   | Every service in the current deployment (MariaDB 10.11, Redis 7.2, …) |
| CPU, Memory, Disk | Upsun observability metrics: last hour's average as a percentage of each container's limit. Disk is persistent storage only, not `/tmp`. |
| This month | The project's line items on its billing profile's open invoice, plus last month's closed invoice |

Under the table, a **Billing** section shows each billing profile's month so far: project
charges, org-level charges (support, user licenses and discounts that aren't tied to one
project) and the total, next to last month's total. The "This month (est.)" card is the
total across all profiles.

On fixed-plan orgs the open invoice is the full month's price from day one. On flexible
orgs (kb), resource charges are priced for the whole month, but usage charges such as
requests and bandwidth keep growing until the month ends.

Badges turn yellow or red for:

- Drupal: yellow for 10.x, red for older
- PHP: yellow for 8.2, red for older
- Usage: yellow at 80%, red at 90%

The thresholds are constants at the top of `DashboardController`.

## How it works

1. **Collect.** `DashboardCollector` calls the API as the user who owns the token. It
   lists that user's organizations, then every project in each one, then reads each
   project's production environment, current deployment, metrics and `composer.lock`.
   `composer.lock` is only downloaded again when the production commit changes. Last, it
   reads the open and previous invoices of each organization's billing profile.
2. **Store.** The results go into State (`upsun_dashboard.snapshot`). The page only reads
   State, so it never waits on the API.
3. **Refresh.** Data refreshes three ways:
   - Drupal cron, once the data is older than `refresh_interval` (one hour by default).
     kb's cron runs every 19 minutes.
   - The **Refresh now** button on the page. It runs a batch with one project per request,
     so it can't time out.
   - `drush upsun-refresh`
4. **Failures.** If one project fails (for example a 403 because the token's user isn't a
   member of it), its row turns red with the error, and keeps its last good data if it had
   any. A failure reading metrics or `composer.lock` shows as a yellow warning, and the rest
   of the row still updates. Failures are also logged to the `upsun_dashboard` channel.

## Setup

1. **Create an API token** in the Upsun console under *My profile → API tokens*. The token
   acts as that user, so the dashboard shows exactly the projects that user can see. Costs
   need billing access to each organization. Organizations the user doesn't own (such as
   Brooks Digital) have no billing profile, so their projects show no cost. A dedicated
   read-only account with billing access would be best.
2. **Store it as a sensitive variable on the kb project** (not in git, not in config):

   ```bash
   upsun variable:create -p q3r62pwvyzjca --level=project \
     --name=env:UPSUN_API_TOKEN --sensitive=true \
     --visible-build=false --visible-runtime=true --value='TOKEN'
   ```

   `settings.php` reads it into `$settings['upsun_dashboard']['api_token']`.
3. **Enable the module and grant the permission**, then export config:

   ```bash
   drush en upsun_dashboard
   drush role:perm:add it 'view upsun dashboard'   # administrators already have every permission
   drush cex
   ```

4. **Collect the first data**, either with **Refresh now** or `drush upsun-refresh`.

For local work, put `UPSUN_API_TOKEN` in `.ddev/.env`.
