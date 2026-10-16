# KB Chat

Supporting code for the kb staff chat, which is built on the AI module (Assistant, Chatbot and
AI Search) with Claude for answers and Gemini for embeddings.

It tracks AI costs, adds page links to search results, shows the chat inside the page
when its block is in the Content region, and syncs Google Drive documents into the chat.

## Who sees what in the chat

The chat's search runs through Search API, and AI Search checks every result against the
asking user's view access. Drupal pages are filtered by kb's existing Node access rules, so
a management-only page never reaches Claude for a staff user. **The assistant's RAG setting
"Allow access bypass" must stay off**, or this check is skipped.

## AI costs

The **AI costs this month** block (category KB Chat) shows, for Claude and Gemini:

- **Live estimate:** kb's own figure. Every AI call's token counts are saved in the
  `kb_chat_usage` table and priced with `$settings['kb_chat']['prices']` in `settings.php`.
  It updates with every call. Calls to a model with no price are counted but flagged.
- **Billed by provider:**
  - **Anthropic:** the month-to-date total from Anthropic's cost report. Anthropic reports
    costs within about five minutes. kb refreshes it hourly on cron, or on demand with
    `drush kb-costs`.
  - **Google:** the month-to-date cost from the project's budget. Google pushes it to kb
    several times a day. It's an estimate and a few hours behind.

Only roles with **View AI costs** see the block. Months are calendar months in UTC, the way
both providers bill.

If the estimate and the billed figure drift far apart, something other than kb is using the
same key or project, or a price in settings is out of date.

### Setup: Anthropic

1. In the Claude Console, an organization admin creates an **Admin API key**
   (`sk-ant-admin...`). It's a different key type from the chat key, and individual accounts
   can't create one.
2. Store it as a sensitive Upsun variable `env:ANTHROPIC_ADMIN_API_KEY`.
3. Optional but recommended: put kb's chat key in its own workspace, and store the workspace
   ID as `env:ANTHROPIC_WORKSPACE_ID` (`wrkspc_...`) so only kb's costs count.

### Setup: Google

1. Generate a random token, for example with `openssl rand -hex 32`, and store it as a
   sensitive Upsun variable `env:GOOGLE_BILLING_PUSH_TOKEN`.
2. In Google Cloud **Billing → Budgets & alerts**, create a budget scoped to the kb project,
   for example $25/month. Under **Manage notifications**, connect a new Pub/Sub topic.
3. In **Pub/Sub → Subscriptions**, create a **push** subscription on that topic, with this
   endpoint:

   ```
   https://kb.faithcatholic.com/kb-chat/billing/google?token=THE_TOKEN
   ```

   For sandbox, use the sandbox URL. A wrong token gets a 403, and Pub/Sub retries.
4. The first notification can take a few hours to arrive.

### Enable

```bash
drush en kb_chat
drush role:perm:add management 'view ai costs'
drush cex
```

Then place the block in the region of your choice under *Structure → Block layout*.

## Google Drive documents

Files in Google **shared drives** are synced into **Shared drive document** entities, which the
chat's search index covers alongside nodes. Answers link to the file in Drive.

### Who sees what

Two service accounts decide each file's tier, so Google's own sharing rules do the work:

| Account | Group membership | Its files become |
|---|---|---|
| Staff indexer (`GOOGLE_DRIVE_SA_JSON`) | **allemployees@** only | **staff** documents |
| Management indexer (`GOOGLE_DRIVE_MGMT_SA_JSON`) | **mgtteam@** only | **management** documents, if the staff account can't open them |

A file the staff account can open is a staff document, even if mgtteam can open it too. A
file in no shared drive either account can see isn't indexed. That includes files only in
someone's My Drive, or shared with "anyone at faithcatholic.com".

Viewing each tier needs a permission. AI Search checks it for every search result:

- **View staff documents in the kb chat:** FAITH Staff and Management
- **View management documents in the kb chat:** Management only

### Sync

`drush kb-drive` (`kb-chat:drive-sync`) runs hourly from Upsun cron with a 25-minute limit:

- It **lists** every file both accounts can see. If either listing fails, nothing changes.
- It **reads** new and changed files until the time limit; the rest wait for the next run.
- It **relabels** files whose sharing changed.
- It **deletes** documents for files that were removed or unshared.

Supported types: Google Docs, Sheets (every sheet) and Slides, PDF (text layer only, not
scanned images), Word (.docx), Excel (.xlsx), plain text, CSV and Markdown. Files over
25 MB are skipped. Settings are in `$settings['kb_chat']['drive']`, including
`exclude_drives` to leave whole shared drives out.

### Setup

1. **Second service account.** In the same Google Cloud project, create a service account
   (for example `kb-drive-mgmt`), create a JSON key, and store it as a sensitive Upsun
   variable `env:GOOGLE_DRIVE_MGMT_SA_JSON`.
2. **Groups.** In Google Workspace, make sure:
   - the **existing** indexer account is a member of **allemployees@ only** (remove it from
     mgtteam@)
   - the **new** account is a member of **mgtteam@ only**
3. **PDF library.** Run `composer require smalot/pdfparser:^2.13`.
4. **Deploy, then:**
   ```bash
   drush updb -y          # creates the document table
   drush role:perm:add faith_staff 'view kb chat staff documents'
   drush role:perm:add management 'view kb chat staff documents,view kb chat management documents'
   drush kb-drive --time-limit=600
   ```
5. **Search index.** Edit the chat's index:
   - add the **Shared drive document** datasource
   - edit the **Rendered HTML output** field and choose the **Default** view mode for Shared
     drive document; documents render their text, so this one field stays Main content for both
     pages and documents
   - the existing **Title** field covers documents too (Contextual content); don't add the
     document **Text** field as Main content: AI Search allows only one
   - index

## Magazine archive

The **Search the magazine archive** agent tool (`kb_chat:magazine_archive_search`) searches
every published issue at catholicmagazines.org. It calls that site's `/api/archive-search`
endpoint (the `archive_search_api` module in the magazines repo), which queries the
archive's Solr index of the PDF text as an anonymous visitor. Claude can pass keywords, an
optional client and a year range, and gets back each issue's details, links and highlighted
excerpts.

Setup: store the same token as `ARCHIVE_SEARCH_TOKEN` on the magazines project and
`MAGAZINE_ARCHIVE_TOKEN` on kb (the URL is in `$settings['kb_chat']['magazine_archive']`).
Then enable the tool on the KB Chat agent.

## Data warehouse

The **Query the data warehouse** agent tool (`kb_chat:warehouse_query`) answers statistics
questions from the BigQuery project `faith-data-warehouse`, using views curated for the chat:

- **`kb_chat`** (everyone with the chat): subscriptions, plans, invoices, renewals, trials,
  pre-2024 subscription orders, store orders and items, diocese statistics, the parish
  directory and Mass times churches.
- **`kb_chat_management`** (Management): agency clients, jobs and billing from Advantage.

Called with no SQL, the tool returns the views' descriptions and columns, read live from
BigQuery and cached for an hour. Called with SQL, it dry-runs the query first and refuses it
unless:

- it is a single `SELECT`
- it names no dataset other than the chat's
- it scans at most 1 GB

Then it runs the query (BigQuery enforces the same byte limit) and returns up to 200 rows.

Queries run as the Drive service accounts:

- Users with *View management documents in the kb chat* use the management account, which
  can read `kb_chat_management`.
- Everyone else uses the staff account, which can't read that dataset, so Google enforces
  the split.

Both accounts have **BigQuery Data Viewer** on the chat datasets and the source datasets the
views read, and need **BigQuery Job User** on the project. The views leave out customer
names, emails, phone numbers and street addresses. View and column descriptions double as
the chat's instructions, so keep them accurate when changing a view. Settings are in
`$settings['kb_chat']['warehouse']`.

## Trello

The **Search Trello** agent tool (`kb_chat:trello_search`) searches FAITH's Trello boards
live. For each card it returns the board, list (status), due date, members, labels,
description, recent comments and link. It can also list one board's open cards by list.

It only reads the boards in `$settings['kb_chat']['trello']['boards']`:

- the **staff** list, for everyone with the chat
- the **management** list, added for users with *View management documents in the kb chat*

Cards from any other board are dropped, even if the Trello account can see them.

Setup:

1. Use a dedicated Trello account and add it to every board on the list.
2. Create an API key in Trello's Power-Up admin portal, then generate a read token for that
   account.
3. Store them as the sensitive Upsun variables `env:TRELLO_API_KEY` and `env:TRELLO_TOKEN`.
4. Check with `drush kb-trello`, which lists any board the account can't see.
5. Enable the tool on the KB Chat agent.
