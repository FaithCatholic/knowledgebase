# KB Chat

Supporting code for the kb staff chat, which is built on the AI module (Assistant, Chatbot and
AI Search) with Claude for answers and Gemini for embeddings.

So far it tracks AI costs. Access control for Google Drive documents is added in phase 2.

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
