# Deploying on Oderland (cPanel + LiteSpeed): step by step

ranatec.com is hosted on Oderland. It runs **LiteSpeed** and **PHP 8.2**, managed through **cPanel**. The WordPress plugin goes on Oderland. The **MCP server runs on Render** at `https://agentic-mcp-sme-ranatec.onrender.com/mcp` (`render.yaml` in the repo root). Ignore `ranatec-mcp/nginx-mcp.conf`.

Files you need, all in `release/`:

| File | Used in |
|---|---|
| `ranatec-api-<version>.zip` | Part A (WordPress plugin) |
| `render.yaml` (repo root) | Part C (MCP server on Render) |
| `ranatec-mcp-<version>-cpanel.zip` | Only for the cPanel alternative at the end of Part C |
| `web-root/robots-addition.txt` (inside the package) | Part B |

Expect about 30–45 minutes. Do it in a quiet hour, and keep the **Before you start** answers to hand.

---

## Before you start

1. **Confirm the open questions with Ranatec:**
   - Agent quote requests go to `info@ranatec.com` (confirmed).
   - Current CEO: not confirmed. The package no longer names a current CEO, so nothing needs changing.
   - MCP server: Render, at `https://agentic-mcp-sme-ranatec.onrender.com/mcp` (confirmed).
2. **Get access:** a WordPress admin login for ranatec.com, and Ranatec's cPanel login at Oderland (via Oderland's customer portal).
3. **Take a backup.** In cPanel, use **Backup** (or JetBackup if listed) to take a full account backup, or at least the files and database.
4. **Render account** with access to the GitHub repo that holds the MCP server. No DNS changes are needed: the server uses Render's own address.

---

## Part A: WordPress plugin (main deliverable, ~10 min)

5. Go to WordPress admin → **Plugins → Add New → Upload Plugin** → choose `ranatec-api-<version>.zip` → **Install Now** → **Activate**.
6. Go to **Settings → Permalinks → Save Changes**, without changing anything. This registers the new URLs. If you skip it, every endpoint returns 404. *(Tools → Ranatec Agent API → **Flush rewrite rules** does the same.)*
7. Go to **Tools → Ranatec Agent API**:
   - Set the **contact recipient** (default `info@ranatec.com`) and save.
   - Check that the **Writable** column says *yes* for all data files. It should on Oderland, because PHP runs as the account user.
   - Click **Sync now**. The report should show news ≈ 44 and products ≈ 82, with no errors.
8. **LiteSpeed Cache:** if the plugin is installed, go to **LiteSpeed Cache → Cache → Excludes → Do Not Cache URIs**, add `/agent/v1/contact.json` and save. Then **Toolbox → Purge All**.
9. **Yoast SEO:** make sure Yoast's *llms.txt* feature is **off** (Yoast → Settings → Site features). Otherwise Yoast writes its own `llms.txt` file, and that file overrides ours.
10. **Test in a browser.** Each URL should load, not 404:
    - https://ranatec.com/agent/
    - https://ranatec.com/llms.txt
    - https://ranatec.com/ai.txt
    - https://ranatec.com/openapi.json
    - https://ranatec.com/api-catalog.json
    - https://ranatec.com/.well-known/api-catalog
    - https://ranatec.com/agent/v1/index.json
    - https://ranatec.com/agent/v1/products/ri-268.json
11. **Test the contact endpoint.** This sends a real email to the recipient from step 7, so tell them first:

    ```bash
    curl -s -X POST https://ranatec.com/agent/v1/contact.json -H "Content-Type: application/json" -d '{
      "agent_context":{"user_authorized_submission":true,"agent_name":"deployment-test"},
      "person":{"name":"Deployment Test","email":"you@gomogroup.com"},
      "company":{"name":"GO MO Group"},
      "inquiry":{"type":"general","message":"Deployment test of the Ranatec agent contact endpoint - please ignore."}}'
    ```
    You should get `"status":"received"` back and an email in the inbox. If no email arrives, install an SMTP plugin (e.g. *WP Mail SMTP*) and connect it to a ranatec.com mailbox on Oderland. That also improves SPF/DKIM deliverability.
12. **WP-Cron.** The plugin syncs news and products daily via WP-Cron. If `wp-config.php` contains `DISABLE_WP_CRON`, add a cPanel **Cron Job**, e.g. every 15 min: `wget -q -O - https://ranatec.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1`.

**If `/.well-known/api-catalog` returns 404** (some cPanel servers reserve `.well-known`): in **File Manager**, open `public_html/.well-known/`, upload `web-root/.well-known/api-catalog` there, and add this to `public_html/.well-known/.htaccess`:
```apache
<Files "api-catalog">
  ForceType application/linkset+json
</Files>
```

---

## Part B: robots.txt (~5 min)

13. Go to **Yoast SEO → Tools → File editor** (or edit `public_html/robots.txt` in File Manager if it's a physical file). Paste the contents of `web-root/robots-addition.txt` at the **end**, then save. Leave the existing lines unchanged.
14. Open https://ranatec.com/robots.txt and check that the new lines appear.

---

## Part C: MCP server on Render (~15 min)

15. In Render, go to **New → Blueprint**, connect `Development-gomo/Agentic-SME-Ranatec` and pick the release branch, then click **Apply**. `render.yaml` sets everything:
    - root folder `ranatec-mcp`
    - build `npm ci --include=dev && npm run build`, start `npm start`
    - Node 22, Frankfurt, health check `/live`
    - environment variables `RANATEC_API_BASE=https://ranatec.com/agent/v1` and `TRUST_PROXY=1`
16. Keep the **Starter** plan. The free plan sleeps when idle, so the first agent call after a quiet period is slow.
17. The service must be named **`agentic-mcp-sme-ranatec`** so its address is `https://agentic-mcp-sme-ranatec.onrender.com`. That address is what the plugin, agent page, llms.txt, ai.txt, OpenAPI and API catalogs advertise.
18. Build command: `npm ci --include=dev && npm run build` (plain `npm run build` fails because the dependencies aren't installed). Start command: `npm start`.
19. If you leave the Root Directory empty, the repo root must contain `package.json`, `package-lock.json`, `tsconfig.json` and `src/`. Otherwise set Root Directory to `ranatec-mcp`.
20. **Test it:**
    - `https://agentic-mcp-sme-ranatec.onrender.com/mcp/tools` should list 12 tools.
    - `https://agentic-mcp-sme-ranatec.onrender.com/mcp/health` should return `"status":"ok"` and `"upstream":"ok"`. Part A must be live for this.
    - Opening `https://agentic-mcp-sme-ranatec.onrender.com/mcp` in a browser returning **405** is correct; MCP clients use POST.
21. **End-to-end test** from your machine, using the unzipped package. The last check sends one real quote-request email:
    ```bash
    cd ranatec-mcp && npm ci && MCP_URL=https://agentic-mcp-sme-ranatec.onrender.com/mcp npm test
    ```
22. **Optional:** connect it in an MCP client, e.g. a custom connector with URL `https://agentic-mcp-sme-ranatec.onrender.com/mcp`.

**Where agent leads go** (no reCAPTCHA or form is touched):

| Agent request | Saved as |
|---|---|
| `quote_request` with products | A **WooCommerce order**, like "Add to RFQ" + checkout: payment method `yith-request-a-quote`, status **New Quote Request** (or the status set in Tools → Ranatec Agent API), the products as line items, configured options as their own lines ("Addon/Accessory for: <product>"), billing = customer, customer note = full request, plus a private note saying it was created by an AI agent, with the lead ID. |
| Any other enquiry (technical question, custom solution, distributor, general) | An entry of the contact form (CF7 form **50**) in **Advanced CF7 DB**, next to website leads. |
| A quote whose product can't be found in WooCommerce | Saved in Advanced CF7 DB instead, so the lead is never lost. |

A notification email also goes to info@ranatec.com; you can switch it off.
- In **Tools → Ranatec Agent API**, check the following:
  - **Lead storage** says "tables found ✓".
  - **Product quote requests**: set the status to the one your real RFQ orders get (open a recent RFQ order in WooCommerce → Orders and compare).
- Phone is required, because the contact form and the checkout require it.
- After deploying, send one agent quote. Then compare the new order side by side with a real RFQ order: status, payment method and line items.

**Lock lead submission to the MCP server (recommended).** This enforces "leads only via `submit_inquiry`":
1. Generate a long random key, e.g. `openssl rand -hex 24`.
2. In **Render**, go to **Environment** and add `RANATEC_MCP_KEY` = the key, then **Save** (Render redeploys).
3. In **WordPress**, go to **Tools → Ranatec Agent API → MCP server key**, paste the same key, and click **Save**.
4. Test it:
   - A direct `POST https://ranatec.com/agent/v1/contact.json` now returns **403 `use_mcp_submit_inquiry`**.
   - `submit_inquiry` through the MCP server still returns `"status":"received"`.
   - If the two keys differ, every lead is refused, so set both at once. Tick **Remove the key** in WordPress to unlock.

**Connect AI clients to the MCP server.** An AI that isn't connected can only browse, and it ends up at the reCAPTCHA-protected web form:
- **Claude Code:** run `claude mcp add --transport http ranatec https://agentic-mcp-sme-ranatec.onrender.com/mcp`, then type `/mcp` to check it's connected.
- **Claude app / claude.ai:** go to **Settings → Connectors → Add custom connector**, enter `https://agentic-mcp-sme-ranatec.onrender.com/mcp`, then enable it in the chat.
- **Codex:** add this to `~/.codex/config.toml`:
  ```toml
  [mcp_servers.ranatec]
  url = "https://agentic-mcp-sme-ranatec.onrender.com/mcp"
  ```
  This needs a Codex version with HTTP MCP support; check with `codex mcp --help`.
- **Agents without a connector** that can make HTTP requests (Codex and Claude Code via curl) can call `submit_inquiry` with a single POST. The copy-paste example is on https://ranatec.com/agent/#submit-a-lead and in llms.txt.

**If something fails:** check the Render **Logs** tab. A build error like `Cannot find module 'express'` means the build command is missing `npm ci --include=dev`. If `/health` shows `"upstream":"http_404"`, finish Part A (and the Permalinks save) first.

**Alternative (not used): cPanel Setup Node.js App on Oderland.** Upload `ranatec-mcp-<version>-cpanel.zip` to a `ranatec-mcp` folder outside `public_html`, create the app with startup file `app.cjs` and set the `RANATEC_API_BASE` variable, then click **Run NPM Install** → **Restart**. If you serve it at a different URL, change `RANATEC_MCP_URL` in `ranatec-api/ranatec-api.php` and run `bash tools/release.sh`.

---

## Part D: after go-live

21. **Google Search Console:** use URL inspection, then request indexing for `https://ranatec.com/agent/` and `https://ranatec.com/llms.txt`. Do the same in Bing Webmaster Tools.
22. **Optional footer link:** add "For AI agents" → `/agent/` in the site footer, so crawlers discover the page.
23. **Share `docs/FINDINGS.md` with Ranatec's web team.** Start with the swapped RF2037/RF2038 specs, the 13 duplicate product listings and the en-GB/en-CA legacy articles.
24. **When content changes:** news posts and products update the API automatically every day, or immediately via **Sync now**. To refresh the agent page and llms files as well, run `python3 tools/build_static.py` then `bash tools/release.sh`, and upload the new plugin zip: WordPress asks to **replace** the current version, which you should confirm.

## Rollback

- **Plugin:** go to **Plugins → Ranatec Agent API → Deactivate**, then **Settings → Permalinks → Save**. The site goes back to exactly how it was. The plugin doesn't change any WordPress content.
- **MCP server:** suspend the Render service.
- **robots.txt:** remove the pasted block.
