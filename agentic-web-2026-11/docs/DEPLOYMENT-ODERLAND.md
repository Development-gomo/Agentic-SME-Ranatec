# Deploying on Oderland (cPanel + LiteSpeed): step by step

ranatec.com is hosted on Oderland. It runs **LiteSpeed** and **PHP 8.4** (8.4.28 in November 2026), managed through **cPanel**. The WordPress plugin goes on Oderland. The **MCP server runs on Render** at `https://agentic-mcp-sme-ranatec.onrender.com/mcp` (built from the GitHub repo `Development-gomo/agentic-mcp-sme-ranatec`). Ignore `ranatec-mcp/nginx-mcp.conf`.

Files you need, all in `release/`:

| File | Used in |
|---|---|
| `ranatec-api-<version>.zip` | Part A (WordPress plugin) |
| `ranatec-mcp/` (inside the package) | Part C (MCP server on Render) |
| `render.yaml` (inside the package) | Part C, only to recreate the Render service |
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
8. **LiteSpeed Cache:** if the plugin is installed, go to **LiteSpeed Cache → Cache → Excludes → Do Not Cache URIs**, add `/agent/v1/contact.json` and `/agent/v1/quote.json` and save. Then **Toolbox → Purge All**.
9. **Yoast SEO:** make sure Yoast's *llms.txt* feature is **off** (Yoast → Settings → Site features). Otherwise Yoast writes its own `llms.txt` file, and that file overrides ours.
10. **Test in a browser.** Each URL should load, not 404:
    - https://ranatec.com/agent/
    - https://ranatec.com/llms.txt
    - https://ranatec.com/ai.txt
    - https://ranatec.com/openapi.json
    - https://ranatec.com/api-catalog.json
    - https://ranatec.com/.well-known/api-catalog
    - https://ranatec.com/.well-known/ard.json (ARD manifest; should be JSON with an `entries` array, not an HTML 404 page)
    - https://ranatec.com/agent/v1/index.json
    - https://ranatec.com/agent/v1/products/ri-268.json
11. **Test both submission endpoints as dry runs.** Nothing is stored or emailed. Run these before you set the MCP key in Part C; afterwards, direct calls get 403.

    ```bash
    # Contact enquiry → would go to Advanced CF7 DB
    curl -s -X POST https://ranatec.com/agent/v1/contact.json -H "Content-Type: application/json" -d '{
      "agent_context":{"user_authorized_submission":true,"agent_name":"deployment-test","dry_run":true},
      "person":{"name":"Deployment Test","email":"you@gomogroup.com","phone":"+46 31 000 00 00"},
      "company":{"name":"GO MO Group"},
      "inquiry":{"type":"general","message":"Deployment test of the Ranatec agent contact endpoint."}}'

    # Product quote → would become a WooCommerce quote order (checks the products exist in the shop)
    curl -s -X POST https://ranatec.com/agent/v1/quote.json -H "Content-Type: application/json" -d '{
      "agent_context":{"user_authorized_submission":true,"agent_name":"deployment-test","dry_run":true},
      "customer":{"first_name":"Deployment","last_name":"Test","email":"you@gomogroup.com","phone":"+46 31 000 00 00","company":"GO MO Group",
                  "country":"SE","address_1":"Testgatan 1","city":"Göteborg","postcode":"41250"},
      "products":[{"id":"shield-box-ri-181","quantity":1,"configuration":[{"id":"feedthrough-filter-ri-4182","quantity":2}]}]}'
    ```
    Both should return `"status":"valid"` and `"submitted":false`. Remove a field (e.g. `city`) to see the refusal that lists the missing field. To check email delivery, send one real enquiry from an AI client (Part C). If no email arrives, install an SMTP plugin (e.g. *WP Mail SMTP*) and connect it to a ranatec.com mailbox on Oderland. That also improves SPF/DKIM deliverability.
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
14. Open https://ranatec.com/robots.txt and check that the new lines appear, including
    `Agentmap: https://ranatec.com/.well-known/ard.json`. Paste this only after step 10 shows that URL returning JSON:
    Lighthouse tries an `Agentmap:` location first, and an advertised URL that fails scores 0.
    If robots.txt is WordPress' virtual file (no physical file, and Yoast's editor is not used), the plugin adds the
    `Agentmap:` line by itself.

---

## Part C: MCP server on Render (~15 min)

15. **Updating the existing service (the normal case).** The live service **`agentic-mcp-sme-ranatec`** builds from the GitHub repo `Development-gomo/agentic-mcp-sme-ranatec` and deploys automatically on every push. That repo's root holds the contents of this package's `ranatec-mcp/` folder. To update it, copy `src/`, `package.json`, `package-lock.json` and `tsconfig.json` from `ranatec-mcp/` into the repo root, commit and push, and wait for the deploy to finish in Render's **Events** tab.
16. **Check the environment** in Render → the service → **Environment**:
    - `NODE_ENV=production`
    - `NODE_VERSION=22`
    - `RANATEC_API_BASE=https://ranatec.com/agent/v1`
    - `TRUST_PROXY=1`
    - `RATE_LIMIT_PER_MIN=100`
    - optional `RANATEC_MCP_KEY` (the same value as **MCP server key** in WordPress → Tools → Ranatec Agent API)
    - optional `MCP_ALLOWED_ORIGINS`: browser origins allowed to call `/mcp`; the default is `https://ranatec.com,https://www.ranatec.com`. MCP clients and server-side agents send no `Origin` header and are not affected.
17. **Recreating the service (only if it was deleted).** Use **New → Blueprint** with `render.yaml` from this package. It names the service `agentic-mcp-sme-ranatec`, which gives the advertised address `https://agentic-mcp-sme-ranatec.onrender.com`; that address is what the plugin, agent page, llms.txt, ai.txt, OpenAPI, ARD and API catalogs advertise. Read the comment at the top of `render.yaml` first: `rootDir` is relative to the repository root. **Don't** apply the older `render.yaml` at the root of `Development-gomo/Agentic-SME-Ranatec`: it builds the previous package's server. Keep the **Starter** plan; the free plan sleeps when idle, so the first agent call after a quiet period is slow. Build command `npm ci --include=dev && npm run build` (plain `npm run build` fails because the dependencies aren't installed), start command `npm start`, health check `/live`.
18. **Check the version.** `https://agentic-mcp-sme-ranatec.onrender.com/mcp/health` must show the package version (e.g. `"version":"1.3.4"`), `"status":"ok"` and `"upstream":"ok"`; Part A must be live for the last one. `/mcp/tools` should list 13 tools, including `request_quote` and `submit_inquiry`. Opening `/mcp` in a browser returns **405**, which is correct: MCP clients use POST.
19. **Read-only test against production** from your machine, using the unzipped package. It reads data and sends both submission tools as **dry runs**: nothing is stored, ordered or emailed.
    ```bash
    cd ranatec-mcp && npm ci && MCP_URL=https://agentic-mcp-sme-ranatec.onrender.com/mcp npm test
    ```
    The first line says `SMOKE_MODE=readonly`. Don't set `SMOKE_MODE=staging` or `stub` against production: those modes send real enquiries and quote orders. As a safety net they refuse to start (`REFUSED`, nothing sent) when the MCP server under test uses the live `ranatec.com` API.
20. **Real submissions are tested on staging, not production.** On a staging copy of ranatec.com with the same plugins (WooCommerce, YITH Request a Quote, Advanced CF7 DB, Contact Form 7) and outgoing email captured (for example with a mail-logging plugin that stops delivery), run the MCP server against staging and then the full test:
    ```bash
    RANATEC_API_BASE=https://<staging-host>/agent/v1 PORT=3000 npm start      # in one terminal
    SMOKE_MODE=staging MCP_URL=http://127.0.0.1:3000/mcp npm test             # in another
    ```
    Then check on staging that:
    - one contact enquiry appears in **Advanced CF7 DB** under the contact form;
    - exactly two quote orders appear in **WooCommerce → Orders** with status *New Quote Request*, the checkout fields and the configured option lines (every other quote check is a dry run);
    - the captured emails went to the contact recipient.
21. If a real submission ever has to be checked in production, make it one deliberate enquiry agreed with Ranatec, then delete the lead or order afterwards.
22. **Optional:** connect it in an MCP client, e.g. a custom connector with URL `https://agentic-mcp-sme-ranatec.onrender.com/mcp`.

**Where agent leads go** (no reCAPTCHA or form is touched):

| Agent request | Saved as |
|---|---|
| MCP tool `request_quote`: product quote (`POST /agent/v1/quote.json`) | A **WooCommerce order**, like "Add to RFQ" + checkout: payment method `yith-request-a-quote`, status **New Quote Request** (or the status set in Tools → Ranatec Agent API), the products as line items, configured options as their own lines ("Addon/Accessory for: <product>"), billing **and** shipping address = the checkout fields, the checkout's **Additional Note** = the user's note, plus a private order note saying it was created by an AI agent, with the quote ID. **Required, as on the checkout:** first name, last name, email, phone, company, country, address, city, postal code, and state/county when the country has them (e.g. US, Canada). Optional: apartment/suite, note. |
| MCP tool `submit_inquiry`: contact enquiry, **no products** (`POST /agent/v1/contact.json`) | An entry of the contact form (CF7 form **50**) in **Advanced CF7 DB**, next to website leads. **Required, as on the form:** name, email, phone, company, plus a message. |

The two never mix. `submit_inquiry` refuses products and tells the agent to use `request_quote`. A quote is never saved as a contact-form lead: if the order can't be created (a product missing from the shop, or WooCommerce off), the agent gets an error and is told to email info@ranatec.com.

A notification email also goes to info@ranatec.com; you can switch it off.
- In **Tools → Ranatec Agent API**, check the following:
  - **Lead storage** says "tables found ✓".
  - **Product quote requests**: set the status to the one your real RFQ orders get (open a recent RFQ order in WooCommerce → Orders and compare).
- After deploying, send one agent quote. Then compare the new order side by side with a real RFQ order: status, payment method and line items.

**Lock lead submission to the MCP server (recommended).** This enforces "submissions only via the MCP tools":
1. Generate a long random key, e.g. `openssl rand -hex 24`.
2. In **Render**, go to **Environment** and add `RANATEC_MCP_KEY` = the key, then **Save** (Render redeploys).
3. In **WordPress**, go to **Tools → Ranatec Agent API → MCP server key**, paste the same key, and click **Save**.
4. Test it:
   - A direct `POST https://ranatec.com/agent/v1/contact.json` now returns **403 `use_mcp_submit_inquiry`**, and `quote.json` returns **403 `use_mcp_request_quote`**.
   - The tools through the MCP server still work (try a dry run).
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
  Or run `codex mcp add ranatec --url https://agentic-mcp-sme-ranatec.onrender.com/mcp`, restart Codex and check with `/mcp` that the Ranatec tools are listed. If they aren't, Codex will use the browser and the web forms. To make Codex prefer the tools, add to `~/.codex/AGENTS.md`: *"For Ranatec product quotes use the MCP tool ranatec.request_quote, for other enquiries ranatec.submit_inquiry. Never fill in the ranatec.com forms in the browser. Run with dry_run: true first and submit only after I confirm."*
- **Agents without a connector** that can make HTTP requests (Codex and Claude Code via curl) can call `request_quote` / `submit_inquiry` with a single POST. The copy-paste examples are on https://ranatec.com/agent/#submit-a-lead and in llms.txt.

**If something fails:** check the Render **Logs** tab. A build error like `Cannot find module 'express'` means the build command is missing `npm ci --include=dev`. If `/health` shows `"upstream":"http_404"`, finish Part A (and the Permalinks save) first.

**Alternative (not used): cPanel Setup Node.js App on Oderland.** Upload `ranatec-mcp-<version>-cpanel.zip` to a `ranatec-mcp` folder outside `public_html`, create the app with startup file `app.cjs` and set the `RANATEC_API_BASE` variable, then click **Run NPM Install** → **Restart**. If you serve it at a different URL, change `RANATEC_MCP_URL` in `ranatec-api/ranatec-api.php` and run `bash tools/release.sh`.

---

## Part D: after go-live

23. **Check that everything reports the intended version** (replace 1.3.4 with the version you deployed):
    ```bash
    for u in .well-known/ard.json agent/v1/index.json; do curl -s "https://ranatec.com/$u?nocache=$RANDOM" | grep -o '"version": *"[^"]*"' | head -1; done
    curl -s https://agentic-mcp-sme-ranatec.onrender.com/mcp/health        # "version":"1.3.4"
    ```
    The plugin version is also on **Plugins** in WordPress. If `ard.json` shows an older version, a hand-uploaded copy in `public_html/.well-known/` is overriding the plugin: replace or delete it.
24. **Check ARD (the Lighthouse "ARD manifest is valid" audit).** Run these from any computer with Python 3, in the
    package folder:
    ```bash
    curl -sI https://ranatec.com/.well-known/ard.json | grep -iE "^HTTP|content-type"   # 200, application/json
    python3 tools/ard.py validate https://ranatec.com/.well-known/ard.json --check-urls  # → VALID: 2 entries, 0 errors, 0 warnings
    python3 tools/vendor/ard-spec/conformance/bin/conformance-test publisher ranatec.com # official tool → PASS
    ```
    Then run Lighthouse (Chrome DevTools → Lighthouse, or PageSpeed Insights). **ARD manifest is valid** should pass.
    If it doesn't, see the troubleshooting table in `docs/ARD.md`. Purge LiteSpeed first, because an old cached 404
    is the most common cause right after deployment.

25. **Google Search Console:** use URL inspection, then request indexing for `https://ranatec.com/agent/` and `https://ranatec.com/llms.txt`. Do the same in Bing Webmaster Tools.
26. **Footer link:** done. Since November 2026 every page has a "For AI agents" link to `/agent` in the footer, so crawlers discover the page.
27. **Share `docs/FINDINGS.md` with Ranatec's web team.** Start with the swapped RF2037/RF2038 specs, the 13 duplicate product listings and the en-GB/en-CA legacy articles.
28. **When content changes:** news posts and products update the API automatically every day, or immediately via **Sync now**. To refresh the agent page and llms files as well, rebuild from what the site publishes **now**: run `python3 tools/pull_live_data.py` (downloads the synced products, news and categories, and drops references to products that are no longer public), then `bash tools/release.sh`, and upload the new plugin zip. `release.sh` refuses to build if the package data still contains anything the site has withdrawn. After the upload the plugin syncs at once (right away on an admin page, otherwise via WP-Cron within a minute or so), so the data API never falls back to the release copy. When uploading the plugin zip, WordPress asks to **replace** the current version, which you should confirm. If a file was uploaded to `public_html` by hand (for example `public_html/.well-known/ard.json`), replace it with the new `web-root/` copy in the same update, because the web server serves it instead of the plugin. Tools → Ranatec Agent API warns when such a copy is older than the plugin.
29. **When a post or product is password-protected, unpublished or deleted:** the next sync removes it from the data API (`/agent/v1/…`). `llms.txt`, `llms-full.txt` and the `/agent/` page are built into the plugin, so they keep it until the package is rebuilt. WordPress shows an admin warning naming each such item and file. Rebuild the package as in step 28 (`pull_live_data.py` first, then `release.sh`) and upload the new plugin to remove them; the warning clears by itself afterwards. If every post or product is protected, the sync writes an empty list; if nothing at all is published, it keeps the last good file and reports an error.

## Rollback

- **Plugin:** go to **Plugins → Ranatec Agent API → Deactivate**, then **Settings → Permalinks → Save**. The site goes back to exactly how it was. The plugin doesn't change any WordPress content.
- **MCP server:** in Render → the service → **Events**, use **Rollback** on the previous deploy, or suspend the service.
- **robots.txt:** remove the pasted block.
- **Files uploaded to `public_html` by hand** (for example `public_html/.well-known/ard.json`): the web server serves these instead of the plugin, so deactivating the plugin or unticking the ARD switch does **not** remove them. Delete them in cPanel → File Manager (or put back the previous version's copy from that version's `web-root/`), then purge LiteSpeed. Tools → Ranatec Agent API lists every such file and whether it matches the plugin.
- **ARD only:** untick **Tools → Ranatec Agent API → Publish the ARD manifest** and save. The URL, `<link rel="ard">` and
  the virtual-robots `Agentmap:` line disappear; remove a pasted `Agentmap:` line by hand, delete
  `public_html/.well-known/ard.json` if it was uploaded by hand, then purge LiteSpeed.
