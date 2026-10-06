# Deployment checklist: Ranatec Agentic Web package

> **Ranatec is hosted on Oderland (cPanel + LiteSpeed).** Follow **[DEPLOYMENT-ODERLAND.md](DEPLOYMENT-ODERLAND.md)** for the step-by-step guide. This file is the generic reference (nginx, Docker, other hosts).


## Before you start: confirm with Ranatec

- [ ] **Contact recipient.** Agent submissions go to `info@ranatec.com` by default. You can change this in Tools → Ranatec Agent API.
- [ ] **Named contacts.** `company.json`, the agent page and llms.txt list Charlotte Ornstein (Operations) and Leslie Johnsen (PR), as named in the 2025 press releases, plus Magnus Kilian as CEO. Site news last names him as CEO in **2023**, so confirm the current leadership.
- [ ] **MCP hosting.** Decide whether the Node server runs on the same host behind nginx (`/mcp`), or as a container on a separate host. **Chosen: Render, at `https://agentic-mcp-sme-ranatec.onrender.com/mcp`.** Managed WordPress hosts often can't run Node. If you use a subdomain, update `URLS['mcp*']` in `tools/build_static.py` and `mcp_server` in `ranatec-api.php`, then rebuild.

## 0. Verify the package before deploying

`ranatec-mcp/node_modules` and `ranatec-mcp/dist` are not shipped in the zip, so install the dependencies before building or type-checking:

```bash
cd ranatec-mcp
npm ci            # installs TypeScript and the MCP SDK (Windows: same command, or npm.cmd ci)
npm run build     # or: npm run typecheck   (tsc --noEmit)
cd ..
bash tests/run-all.sh   # PHP lint, JSON, contact endpoint with and without mbstring, HTTP endpoints, MCP end-to-end (37 checks)
```

**PHP requirements:** PHP 7.4+, WordPress 6.0+. The `mbstring` extension is recommended but **not required**: the plugin uses its own UTF-8 helpers (`Ranatec_Agent_API::str_len()` / `str_sub()`) that fall back to PCRE when `mbstring` is missing. WordPress core also polyfills `mb_substr` / `mb_strlen`.

## 1. WordPress plugin

- [ ] Zip `ranatec-api/` and upload it via Plugins → Add New → Upload, or copy it to `wp-content/plugins/ranatec-api/`.
- [ ] Activate **Ranatec Agent API**.
- [ ] **Settings → Permalinks → Save**, or Tools → Ranatec Agent API → *Flush rewrite rules*. Without this, every endpoint returns 404.
- [ ] Make sure `wp-content/plugins/ranatec-api/data/` is writable by PHP, so the daily sync can update the files. The admin page shows a *Writable* column.
- [ ] If a caching or CDN plugin is active (WP Rocket, LiteSpeed, Cloudflare), exclude `/agent/v1/contact.json` from caching. The GET endpoints can be cached; they send `Cache-Control: public, max-age=3600`.
- [ ] **Yoast SEO llms.txt feature.** Keep it **off**. If it's enabled it writes its own physical `/llms.txt`, which the web server serves instead of this package's file.
- [ ] Check the endpoints:

```bash
curl -sI https://ranatec.com/llms.txt            | grep -i content-type   # text/plain; charset=utf-8
curl -s  https://ranatec.com/agent/v1/index.json | head -20
curl -s  https://ranatec.com/agent/v1/products/ri-268.json | head -20
curl -s 'https://ranatec.com/agent/v1/news.json?type=product-launch&fields=summary&limit=3'
curl -s  https://ranatec.com/openapi.json        | python3 -c "import json,sys; json.load(sys.stdin); print('Valid JSON')"
curl -sI https://ranatec.com/.well-known/api-catalog | grep -i content-type  # application/linkset+json
curl -s  https://ranatec.com/agent/ | grep -c '<article'                     # > 100
```

- [ ] Test the contact endpoint. This sends a real email to the recipient, so warn them first:

```bash
curl -s -X POST https://ranatec.com/agent/v1/contact.json -H "Content-Type: application/json" -d '{
  "agent_context": {"user_authorized_submission": true, "agent_name": "deployment-test"},
  "person": {"name": "Deployment Test", "email": "you@gomogroup.com"},
  "company": {"name": "GO MO Group"},
  "inquiry": {"type": "general", "message": "Deployment test of the Ranatec agent contact endpoint - please ignore."}}'
# → {"status":"received","lead_id":"ranatec-lead-2026-XXXXXXXX", …}
# Same request with "user_authorized_submission": false → HTTP 403
```

## 2. Static files (optional)

The plugin already serves `/agent/`, `/llms.txt`, `/llms-full.txt`, `/ai.txt`, `/api-catalog.json` and `/.well-known/api-catalog`. Upload the files in `web-root/` only if the host serves the web root before WordPress and you want physical files. A physical file always takes precedence, so remember to re-upload it after every rebuild.

## 3. robots.txt

- [ ] Merge `web-root/robots-addition.txt` into the live robots.txt (Yoast → Tools → File editor). The live file already allows GPTBot, ClaudeBot, PerplexityBot, Google-Extended and others; the addition covers the remaining AI agents, adds discovery lines and keeps cart/checkout out of AI crawls.

## 4. MCP server

```bash
cd ranatec-mcp
npm ci && npm run build
PORT=3000 RANATEC_API_BASE=https://ranatec.com/agent/v1 npm start      # or: docker build -t ranatec-mcp . && docker run -d -p 3000:3000 ranatec-mcp
```

- [ ] Run it under a process manager (systemd / pm2 / Docker `--restart unless-stopped`).
- [ ] Add `nginx-mcp.conf` to the ranatec.com server block, before WordPress's `location /`, then reload nginx.
- [ ] Check it:

```bash
curl -s https://ranatec.com/mcp/health   # {"status":"ok", … "upstream":"ok"}
curl -s https://ranatec.com/mcp/tools    # 12 tools
MCP_URL=https://ranatec.com/mcp npm test # end-to-end. NOTE: the last check sends a real quote request email
```

- [ ] Optional: add the server to an MCP client, e.g. Claude Desktop / Claude Code → `https://ranatec.com/mcp` (Streamable HTTP).

## 5. After launch

- [ ] Submit `https://ranatec.com/llms.txt` and `/agent/` in Google Search Console (URL inspection) and Bing Webmaster Tools.
- [ ] Optional: link `/agent/` from the site footer ("For AI agents") so crawlers discover it.
- [ ] Work through `docs/FINDINGS.md` with the Ranatec web team.
- [ ] After content changes, run `python3 tools/build_static.py` and redeploy `ranatec-api/public/` + `openapi.json`.

## URL consistency

The canonical URLs are defined once, in `tools/build_static.py` (`URLS`) and `ranatec-api/ranatec-api.php` (`RANATEC_API_SITE` / `RANATEC_API_BASE`, `get_index()`). If a URL changes, edit those two places and rebuild. Every other file is generated from them.
