# Design Dashboard

Serves the designer capacity dashboard at **https://kb.faithcatholic.com/design/dashboard**,
visible only to users with the **Management** role.

The dashboard itself is a single static HTML file kept in its own repo,
[FaithCatholic/design_dashboard](https://github.com/FaithCatholic/design_dashboard).
This module doesn't contain the dashboard. It pulls the file in at build time and serves it
through Drupal so access can be checked.

## How it works

```
/app/                         kb repo root on Upsun
├── scripts/clone-script.php  clones design_dashboard (and slides) during the build
├── design_dashboard/         ← cloned here; outside web/, so nginx can't serve it
│   └── index.html
└── web/                      Drupal docroot
    └── modules/custom/design_dashboard/
```

1. **Build.** The build hook in `.upsun/config.yaml` runs `php ./scripts/clone-script.php`,
   which shallow-clones the dashboard repo into `/app/design_dashboard/`. If the clone fails,
   the build fails.
2. **Request.** `/design/dashboard` has no nginx location of its own, so it falls through to
   Drupal (`passthru: '/index.php'`).
3. **Access check.** `design_dashboard.routing.yml` requires `_role: 'management'`. Anyone
   without that role, including administrators, gets a 403.
4. **Response.** `DesignDashboardController` reads `/app/design_dashboard/index.html` and
   returns it as-is (no Drupal theme), with `Cache-Control: private, no-store` so the CDN
   never caches it. If the file is missing, it returns a 404.

Don't add a `/design` or `/design/dashboard` location to `.upsun/config.yaml`. nginx would
then serve the path directly and skip the Drupal access check.

### GitHub access

The dashboard repo is private. The build clones it over HTTPS using a fine-grained GitHub
token stored in the Upsun project variable **`env:DASHBOARD_GITHUB_TOKEN`** (sensitive,
visible at build, not at runtime). The token has read-only **Contents** access to
`design_dashboard` only.

Slides uses the Upsun SSH deploy key instead. That key can't be reused because GitHub allows
a deploy key on only one repo.

Without the variable (for example, locally), the script clones over SSH using your own key.

## Updating the dashboard

1. Edit `index.html` in the **design_dashboard** repo and push to `main`.
2. **Trigger a kb rebuild.** Pushing the dashboard repo doesn't deploy anything by itself.
   Upsun reuses the previous kb build when the kb code hasn't changed, and a redeploy doesn't
   re-clone. Push a kb commit that changes a file. An empty commit keeps the same tree, so it
   reuses the old build.
3. Check the build log for `+ php ./scripts/clone-script.php` with no `Failed to clone` line,
   then load `/design/dashboard`.

Keep `index.html` self-contained: inline CSS and JS, and full URLs for anything external
(Google Fonts is fine). Relative references like `src="logo.png"` or `fetch('data.json')`
won't resolve, because only `index.html` is served. Shared assets can go under `web/` with
absolute paths, but anything there is public.

If you rename or move `index.html` in the dashboard repo, update the path in
`src/Controller/DesignDashboardController.php`.

## Changing who can see it

Edit `requirements` in `design_dashboard.routing.yml`, deploy, then run `drush cr`:

| Access | Requirement |
|---|---|
| Management only (current) | `_role: 'management'` |
| Management or administrators | `_role: 'management+administrator'` |
| Anyone logged in | `_user_is_logged_in: 'TRUE'` |

## Local development

```
php scripts/clone-script.php      # from the kb root; uses your SSH key
ddev drush en design_dashboard -y
```

The script skips repos that are already cloned. To pick up dashboard changes locally, delete
`design_dashboard/` and run it again. The folder is gitignored.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Build fails with `Failed to clone into ./design_dashboard` | Token expired, revoked, not visible at build, or awaiting org approval. Create a new token and update `env:DASHBOARD_GITHUB_TOKEN`. |
| "Page not found" | Module not enabled (`drush en design_dashboard -y`), or `/app/design_dashboard/index.html` missing. `drush route --path=/design/dashboard` should show `design_dashboard.page`. |
| "Access denied" | Account doesn't have the Management role. |
| Old version still showing | kb wasn't rebuilt. See step 2 under *Updating the dashboard*. |
| Page loads unstyled or unresponsive | A Security Kit (seckit) Content Security Policy may be blocking inline scripts and styles. Check `/admin/config/system/seckit`. |

## Maintenance

- **Token expiry:** the GitHub token has an expiry date. When it expires, kb builds fail at
  the clone step. The token belongs to the account that created it, so it also stops working
  if that person loses access to the repo.
- **Deploy hook:** the kb deploy hook doesn't run drush, so after enabling or changing modules,
  run `drush en` or `drush cr` by hand.
