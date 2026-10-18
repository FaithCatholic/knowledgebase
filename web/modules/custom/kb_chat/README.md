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

## Costs

The **KB costs this month** block (category KB Chat) has three parts: AI, Upsun hosting,
and a total of the two.

**AI**, for Claude and Gemini:

- **Live estimate:** kb's own figure. Every AI call's token counts are saved in the
  `kb_chat_usage` table and priced with `$settings['kb_chat']['prices']` in `settings.php`.
  It updates with every call. Calls to a model with no price are counted but flagged.
- **Billed by provider:**
  - **Anthropic:** the month-to-date total from Anthropic's cost report. Anthropic reports
    costs within about five minutes. kb refreshes it hourly on cron, or on demand with
    `drush kb-costs`.
  - **Google:** the month-to-date cost from the project's budget. Google pushes it to kb
    several times a day. It's an estimate and a few hours behind.

**Upsun hosting:** the open invoice of the FAITH Flex organization's billing profile
(`$settings['kb_chat']['upsun_billing_profile']`), next to last month's. kb is the only
project there, so the whole invoice counts: the KB project, plus the organization's
support, user licenses and discounts. The figures come from the **upsun_dashboard**
module's hourly snapshot, so there's no second Upsun token or API call. On a flexible plan,
resources are priced for the whole month from day one, and usage (requests, bandwidth,
builds) grows until month end.

**Total this month:** AI plus hosting. For AI it uses each provider's billed figure where
one has been reported, otherwise kb's live estimate.

Only roles with **View KB costs** (machine name `view ai costs`) see the block. Months are
calendar months in UTC, the way the providers bill. The block's plugin ID is still
`kb_chat_ai_costs`, so the placed block keeps working.

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

## Chat formatting

- **Tables:** the AI module's Markdown converter is plain CommonMark, so tables showed as
  raw pipes. `KbChatServiceProvider` swaps in `Markdown\GfmConverterFactory`, which adds
  GitHub-style tables, strikethrough and bare-URL links. DeepChat's tag filter is patched
  to keep table tags (`patches/ai_chatbot-allow-table-tags.patch`), and `js/inline-chat.js`
  styles tables inside the chat's shadow DOM.
- **Agent instructions:** the agent form's rich-text editor saves typed Markdown escaped
  (`\[Title]\(URL)`), which kept the model from following the citation format. An
  `ai_agent` presave hook removes the escapes on every save.

## Status

The **KB chat status** block (category KB Chat, permission **View KB chat status**) puts
the health of everything that feeds the chat on one screen:

- **Summary:** anything that needs a look, worst first, or "Everything is running normally".
- **Content index:** items indexed and waiting, for kb pages and for Drive files and website
  pages; warns if the vector database is unavailable or "Index items immediately" is on.
- **Google Drive:** documents per tier, when the drives were last listed, files waiting to
  be read (with a rough time to finish at the last run's pace), and files skipped after
  crashing the sync.
- **Trusted websites:** pages indexed and last crawl per website; warns on a website with
  no pages or overdue for a crawl.
- **Cron jobs:** for `kbindex`, `kbdrive` and `kbweb`, when each last started, how long it
  took, its result and its last success. A run that started and never finished was stopped
  by the Upsun timeout or out of memory.

The cron runs are recorded by the `kb-chat:index`, `kb-chat:drive-sync` and
`kb-chat:web-sync` commands (`JobLog`, in state as `kb_chat.job.*`). `kbindex` runs
`drush kb-index` rather than `search-api:index` so its runs are recorded. Crawling one
website by name (`drush kb-web mcc_benefits`) isn't recorded as a `kbweb` run.

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

The sync runs from Upsun cron every 5 minutes as `drush kb-drive --time-limit=240
--max-items=100`, in two parts:

- **Listing:** every 6 hours (`list_interval`), or on demand with `--list`. It goes through
  the drives one at a time and puts **new or changed** files on the `kb_chat_drive` queue. It
  relabels files whose name, drive or sharing changed, and deletes documents for files that
  were removed, unshared or excluded. If either account's listing fails, nothing is deleted.
- **Reading:** every run. It takes up to 100 files or 4 minutes from the queue, reads them and
  saves them. Files that can't be read, such as protected or damaged PDFs, are logged and
  dropped. They're queued again if they change.

`drush kb-drive --drives` shows the shared drives each account can see.

Supported types: Google Docs, Sheets (every sheet) and Slides, PDF (text layer only, up to
`max_pdf_bytes`, 10 MB), Word (.docx), Excel (.xlsx), plain text, CSV and Markdown. Other
files over 25 MB are skipped.

**What to leave out and limits** are set at **Configuration → Search → KB chat Google Drive**
(`/admin/config/search/kb-chat-drive`), stored in the `kb_chat.drive` config:

- shared drives to leave out
- folders to leave out, with everything inside them: a path from the drive
  (`Custom Publishing/Production`) or a folder ID. `drush kb-drive --folders="Custom Publishing"`
  shows indexable files per folder.
- file names to skip, such as contact lists
- single files to leave out (`exclude_files`): a file ID, then its name as a note
- how often to re-list the drives
- the file and PDF size limits

The page also shows when the drives were last listed, how many files are waiting, and any
files that crashed the sync. **Re-list the drives on the next run** applies changes within
5 minutes. The service account keys stay in `settings.php`.

**Priority documents** (top of the Drive settings) are the documents the chat should check
before others, such as the production schedules and the management meeting notes: one per
line, the Drive link (or file ID), then `|` and what it's for. The page lists which document
each line found. They're used two ways:

- The **Read priority documents** agent tool (`kb_chat:priority_documents`) lists them with
  what each is for, and reads one: in full (up to 40,000 characters), or only the lines
  matching keywords, which suits long schedules and running notes. In a spreadsheet each
  match keeps its sheet's name and heading row. It reads the file from Drive as it is now,
  reused for 10 minutes, and falls back to the synced copy if Drive can't be read.
- In knowledge base search results, a priority document is flagged with its note and told
  to take precedence.

Only documents the user may see are listed or read, so a management document stays
management-only. A priority document must be in a synced shared drive.

**Indexed files** (the second tab, `/admin/config/search/kb-chat-drive/files`) lists every
Drive file in the chat, 50 to a page, with its drive, type, who can see it, when it changed
and how much text it has. Filter by name, drive, type or tier, and sort by any column (by
Text to find huge spreadsheets). Tick files and **Ignore selected files**: they come out of
the search index at once and are added to "Files to leave out", so neither the next listing
nor a copy still waiting in the read queue brings them back. They're listed under **Ignored
files** on the same page; **Stop ignoring selected files** takes them off the list and
re-lists the drives, so they're back within one sync.

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

## FAITH publications

The **Search FAITH publications** agent tool (`kb_chat:publications_search`) searches the
articles on every domain of the mothership (the faithmagazine project): Faith Syndicated,
Content Evangelist, FAITH Magazine and the diocesan publications. It calls the mothership's
`/api/kb-search` endpoint (the `kb_search_api` module in the faithmagazine repo), which
queries that site's Solr `default_index` as an anonymous visitor, so only published, public
articles come back. Claude can pass keywords, optional publications and a year range, and
gets back each article's publications, date, author, link and an excerpt. Links point at
the publication the article is on (the one asked for, if it's on several).

Setup: store the same token as `KB_SEARCH_TOKEN` on the faithmagazine project and
`MOTHERSHIP_SEARCH_TOKEN` on kb (the URL is in `$settings['kb_chat']['mothership']`).
Then enable the tool on the KB Chat agent. This replaces crawling faithsyndicated.org and
contentevangelist.com as trusted websites.

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

## Trusted websites

Outside websites the chat should search and cite are managed at **Configuration → Search →
KB chat trusted websites** (`/admin/config/search/kb-chat-websites`). This needs the
*Administer KB chat trusted websites* permission.

Each website has:

- a name, used to cite it
- start URLs
- the URL prefixes the crawl may follow, which keep it to the trusted section
- whether linked PDF, Word and Excel files are included
- **how often it changes** (daily, weekly or monthly)
- who can see it (all staff or management)
- a page limit

The list shows each website's indexed page count and last crawl.

How it works:

- **Crawling:** `drush kb-web --due --time-limit=240` runs every 10 minutes from Upsun cron. It re-crawls enabled
  websites whose interval has passed, and **Crawl now** queues one for the next run. It
  follows robots.txt and fetches one page per second.
- **Storage:** pages are stored as shared drive documents with source `web`. They're indexed
  with everything else, and answers link to the website and say it's an outside source. kb
  doesn't copy their content.
- **Removal:** pages that disappear from a complete crawl are removed. Deleting a website
  removes its pages.
