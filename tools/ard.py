#!/usr/bin/env python3
"""Agentic Resource Discovery (ARD) — manifest generation and validation for the Agentic Web package.

Framework-neutral core: pure Python 3 standard library, no website- or framework-specific code. It turns
resource descriptions the package already has (site identity, MCP server, OpenAPI spec, …) into an ARD
manifest for /.well-known/ard.json, following ARD v0.91 (https://agenticresourcediscovery.org/spec/).

Used two ways:
  1. As a library by tools/build_static.py, which passes the package's existing metadata (single source of truth).
  2. As a CLI for projects that do not use build_static.py (e.g. a Next.js site):
       python3 tools/ard.py build    --config ard.config.json --out public/.well-known/ard.json
       python3 tools/ard.py validate public/.well-known/ard.json          (or an https:// URL)
       python3 tools/ard.py validate https://example.com/.well-known/ard.json --check-urls

Resource input (all keys optional; only resources that are actually configured become entries):
  site    = {"url": "https://example.com", "name": "Example Ltd", "description": "…"}
  mcp     = [{"name": "example-mcp", "title": "…", "description": "…", "endpoint": "https://…/mcp",
              "transport": "streamable-http", "version": "1.2.0", "tools": [{"name": "…"}], "websiteUrl": "…",
              "cardUrl": "https://…/mcp/server-card"   # optional: hosted Server Card; else the card is inlined
              "queries": ["…"], "tags": ["…"]}]
  openapi = [{"url": "https://…/openapi.json", "title": "…", "description": "…", "version": "…",
              "operations": ["listProducts", …], "baseUrl": "https://…", "queries": […], "tags": […]}]
  a2a     = [{"cardUrl": "https://…/.well-known/agent-card.json", "name": "…", "description": "…", "skills": […]}]
  skills  = [{"url": "https://…/SKILL.md", "name": "…", "description": "…", "format": "md|json|zip|gzip"}]
Config (ard.config.json, optional — see ard.config.example.json):
  enabled, publisher, autoDiscover {mcp, openapi}, site, mcp, openapi, a2a, skills, overrides, entries
"""
import ipaddress
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

SPEC_VERSION = '0.91'
WELL_KNOWN_PATH = '/.well-known/ard.json'
CONTENT_TYPE = 'application/json; charset=utf-8'
HERE = os.path.dirname(os.path.abspath(__file__))
VENDOR = os.path.join(HERE, 'vendor')
ARD_SCHEMA = os.path.join(VENDOR, 'ard-spec', 'spec', 'schemas', 'ard-entry.schema.json')
CONFORMANCE_TOOL = os.path.join(VENDOR, 'ard-spec', 'conformance', 'bin', 'conformance-test')
SERVER_CARD_SCHEMA = os.path.join(VENDOR, 'mcp-server-card', 'server-card.schema.json')

# Media types (ARD §3.3). OpenAPI uses its IANA-registered type; ARD accepts any valid media type.
TYPE_MCP = 'application/mcp-server-card+json'
TYPE_A2A = 'application/a2a-agent-card+json'
TYPE_OPENAPI = 'application/vnd.oai.openapi+json'
TYPE_SKILL = {'md': 'application/agent-skills+md', 'json': 'application/agent-skills+json',
              'zip': 'application/agent-skills+zip', 'gzip': 'application/agent-skills+gzip'}
SERVER_CARD_SCHEMA_URI = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json'

# Same patterns as the official conformance tool / JSON Schema (ARD Appendix C, D).
URN_RE = re.compile(r'^urn:air:([a-zA-Z0-9.-]+)(?::([a-zA-Z0-9._:-]+))?:([a-zA-Z0-9._-]+)$')
_RESTRICTED = r"[0-9A-Za-z][0-9A-Za-z!#$&^_.+\-]{0,126}"
_TOKEN = r"[!#$%&'*+\-.^_`|~0-9A-Za-z]+"
MEDIA_TYPE_RE = re.compile(rf'{_RESTRICTED}/{_RESTRICTED}(?:[ \t]*;[ \t]*{_TOKEN}[ \t]*=[ \t]*(?:{_TOKEN}|"[^"]*"))*')
# Values that must never appear in a public manifest (credentials, signed URLs).
SECRET_KEY_RE = re.compile(r'(api[-_]?key|secret|password|passwd|token|bearer|authorization|credential|private[-_]?key|mcp[-_]key)', re.I)

DEFAULT_CONFIG = {'enabled': True, 'publisher': None, 'autoDiscover': {'mcp': True, 'openapi': True},
                  'site': {}, 'mcp': [], 'openapi': [], 'a2a': [], 'skills': [], 'overrides': {}, 'entries': []}


class ArdError(ValueError):
    """Raised when configuration would produce an invalid or unsafe manifest."""


# ----------------------------------------------------------------------------- helpers

def slug(s, fallback='resource'):
    s = re.sub(r'[^a-z0-9._-]+', '-', str(s or '').lower()).strip('-.')
    return s or fallback


def _host(url):
    return (urllib.parse.urlsplit(url).hostname or '').lower()


def _is_local_host(host):
    if host in ('localhost',) or host.endswith('.localhost') or host.endswith('.local') or '.' not in host:
        return True
    try:
        ip = ipaddress.ip_address(host)
        return ip.is_private or ip.is_loopback or ip.is_link_local or ip.is_reserved
    except ValueError:
        return False


def publisher_for(site_url, override=None):
    """The <publisher> segment of every URN: the configured FQDN, else the site's host (without www.).
    Local hosts are not valid publishers (ARD URN naming guide); they map to the reserved agent.localhost."""
    if override:
        pub = override.lower()
    else:
        host = _host(site_url)
        pub = 'agent.localhost' if not host or _is_local_host(host) else re.sub(r'^www\.', '', host)
    if not re.fullmatch(r'[a-z0-9.-]+', pub) or '.' not in pub:
        raise ArdError(f"publisher '{pub}' must be a fully qualified domain name")
    return pub


def urn(publisher, namespace, name):
    u = f'urn:air:{publisher}:{slug(namespace)}:{slug(name)}'
    if not URN_RE.match(u):
        raise ArdError(f'invalid identifier {u}')
    return u


def _abs(url, site_url):
    return urllib.parse.urljoin(site_url.rstrip('/') + '/', url) if url else url


def _check_public_url(url, label, allow_local):
    p = urllib.parse.urlsplit(url or '')
    if p.scheme not in ('https', 'http') or not p.hostname:
        raise ArdError(f'{label}: url must be absolute http(s), got {url!r}')
    if p.username or p.password:
        raise ArdError(f'{label}: url must not contain credentials')
    for k, _ in urllib.parse.parse_qsl(p.query, keep_blank_values=True):
        if SECRET_KEY_RE.search(k):
            raise ArdError(f"{label}: url query parameter '{k}' looks like a credential")
    if not allow_local:
        if p.scheme != 'https':
            raise ArdError(f'{label}: public url must use https, got {url}')
        if _is_local_host(p.hostname.lower()):
            raise ArdError(f'{label}: {url} is an internal/local address and must not be published')


def _scan_secrets(obj, label, path=''):
    if isinstance(obj, dict):
        for k, v in obj.items():
            if k == 'trustManifest':                     # public identity/attestation data by definition
                continue
            if SECRET_KEY_RE.search(str(k)) and v not in (None, '', False) and not isinstance(v, (dict, list)):
                raise ArdError(f"{label}: member '{path}{k}' looks like a credential and must not be published")
            _scan_secrets(v, label, f'{path}{k}.')
    elif isinstance(obj, list):
        for i, v in enumerate(obj):
            _scan_secrets(v, label, f'{path}{i}.')


def _queries(given, site_name):
    """representativeQueries: 2–5 natural-language queries (ARD §4.2). Falls back to neutral site queries."""
    out = []
    for q in given or []:
        q = str(q).strip()
        if q and q not in out:
            out.append(q)
    for q in ([f'What does {site_name} offer?', f'Tell me about {site_name}'] if site_name else []):
        if len(out) >= 2:
            break
        if q not in out:
            out.append(q)
    return out[:5]


def _short(s, limit):
    s = re.sub(r'\s+', ' ', str(s or '')).strip()
    if len(s) <= limit:
        return s
    cut = s[:limit - 1].rsplit(' ', 1)[0].rstrip(' ,;:—-')
    return cut + '…'


def _clean(entry):
    return {k: v for k, v in entry.items() if v not in (None, '', [], {})}


# ----------------------------------------------------------------------------- entry builders

def mcp_server_card(mcp, publisher):
    """MCP Server Card (SEP-2127, schema v1). Identity + remote transport only — no tool list (by design)."""
    card = {
        '$schema': SERVER_CARD_SCHEMA_URI,
        'name': f"{'.'.join(reversed(publisher.split('.')))}/{slug(mcp['name'])}",
        'version': str(mcp.get('version') or '0.0.0'),
        'description': _short(mcp.get('description') or mcp.get('title') or mcp['name'], 100),
        'title': _short(mcp.get('title'), 100) or None,
        'websiteUrl': mcp.get('websiteUrl'),
        'remotes': [{'type': mcp.get('transport', 'streamable-http'), 'url': mcp['endpoint']}],
    }
    return _clean(card)


def mcp_entry(mcp, ctx):
    label = f"mcp '{mcp.get('name')}'"
    if not mcp.get('name') or not mcp.get('endpoint'):
        raise ArdError(f'{label}: name and endpoint are required')
    _check_public_url(mcp['endpoint'], label, ctx['allow_local'])
    e = {
        'identifier': mcp.get('identifier') or urn(ctx['publisher'], 'mcp', mcp['name']),
        'displayName': mcp.get('title') or mcp['name'],
        'type': TYPE_MCP,
        'description': mcp.get('longDescription') or mcp.get('description'),
        'capabilities': [t['name'] if isinstance(t, dict) else str(t) for t in mcp.get('tools', [])],
        'representativeQueries': _queries(mcp.get('queries'), ctx['site_name']),
        'tags': mcp.get('tags'),
        'version': mcp.get('version'),
        'updatedAt': ctx['updated'],
        'metadata': {'transport': mcp.get('transport', 'streamable-http'), 'endpoint': mcp['endpoint']},
    }
    if mcp.get('cardUrl'):                               # card hosted by the server (e.g. <endpoint>/server-card)
        _check_public_url(mcp['cardUrl'], label, ctx['allow_local'])
        e['url'] = mcp['cardUrl']
    else:                                                # inline card: valid without any extra endpoint
        e['data'] = mcp_server_card(mcp, ctx['publisher'])
    return _clean(e)


def openapi_entry(api, ctx):
    label = f"openapi '{api.get('title') or api.get('url')}'"
    if not api.get('url'):
        raise ArdError(f'{label}: url is required')
    url = _abs(api['url'], ctx['site_url'])
    _check_public_url(url, label, ctx['allow_local'])
    title = api.get('title') or 'API'
    return _clean({
        'identifier': api.get('identifier') or urn(ctx['publisher'], 'api', title),
        'displayName': title,
        'type': TYPE_OPENAPI,
        'url': url,
        'description': api.get('description'),
        'capabilities': api.get('operations'),
        'representativeQueries': _queries(api.get('queries'), ctx['site_name']),
        'tags': api.get('tags'),
        'version': api.get('version'),
        'updatedAt': ctx['updated'],
        'metadata': _clean({'baseUrl': api.get('baseUrl'), 'openapiVersion': api.get('openapiVersion')}) or None,
    })


def a2a_entry(agent, ctx):
    label = f"a2a '{agent.get('name')}'"
    if not agent.get('name') or not agent.get('cardUrl'):
        raise ArdError(f'{label}: name and cardUrl (the agent card) are required')
    url = _abs(agent['cardUrl'], ctx['site_url'])
    _check_public_url(url, label, ctx['allow_local'])
    return _clean({
        'identifier': agent.get('identifier') or urn(ctx['publisher'], 'agent', agent['name']),
        'displayName': agent.get('title') or agent['name'],
        'type': TYPE_A2A,
        'url': url,
        'description': agent.get('description'),
        'capabilities': agent.get('skills'),
        'representativeQueries': _queries(agent.get('queries'), ctx['site_name']),
        'tags': agent.get('tags'),
        'version': agent.get('version'),
        'updatedAt': ctx['updated'],
    })


def skill_entry(skill, ctx):
    label = f"skill '{skill.get('name')}'"
    fmt = skill.get('format', 'md')
    if not skill.get('name') or not skill.get('url') or fmt not in TYPE_SKILL:
        raise ArdError(f'{label}: name, url and format ({"|".join(TYPE_SKILL)}) are required')
    url = _abs(skill['url'], ctx['site_url'])
    _check_public_url(url, label, ctx['allow_local'])
    return _clean({
        'identifier': skill.get('identifier') or urn(ctx['publisher'], 'skill', skill['name']),
        'displayName': skill.get('title') or skill['name'],
        'type': TYPE_SKILL[fmt],
        'url': url,
        'description': skill.get('description'),
        'representativeQueries': _queries(skill.get('queries'), ctx['site_name']),
        'tags': skill.get('tags'),
        'version': skill.get('version'),
        'updatedAt': ctx['updated'],
    })


def custom_entry(raw, ctx):
    """A developer-registered entry: any ARD entry terms. identifier is derived when omitted; relative url resolved."""
    e = dict(raw)
    name = e.get('displayName') or e.get('name')
    label = f"custom entry '{name or e.get('identifier')}'"
    e.pop('name', None)
    ns = e.pop('namespace', 'resource')
    if not e.get('identifier'):
        if not name:
            raise ArdError(f'{label}: displayName (or identifier) is required')
        e['identifier'] = urn(ctx['publisher'], ns, name)
    e.setdefault('displayName', name)
    if 'url' in e:
        e['url'] = _abs(e['url'], ctx['site_url'])
        _check_public_url(e['url'], label, ctx['allow_local'])
    if 'representativeQueries' not in e:
        e['representativeQueries'] = _queries(e.pop('queries', None), ctx['site_name'])
    return e


# ----------------------------------------------------------------------------- manifest

def load_config(path=None):
    cfg = json.loads(json.dumps(DEFAULT_CONFIG))
    if path and os.path.exists(path):
        with open(path, encoding='utf-8') as f:
            user = json.load(f)
        for k, v in user.items():
            if k.startswith('$') or k.startswith('_'):
                continue
            if isinstance(v, dict) and isinstance(cfg.get(k), dict):
                cfg[k].update(v)
            else:
                cfg[k] = v
    return cfg


def build_manifest(site, resources=None, config=None, updated=None, allow_local=False):
    """Return the /.well-known/ard.json document, or None when ARD is disabled or nothing can be published.

    site      — {"url", "name", "description"}; config["site"] values fill in anything missing.
    resources — auto-discovered resources from the package ({"mcp": [...], "openapi": [...], ...}).
    config    — load_config() result; adds resources/entries, overrides, enabled/publisher.
    """
    cfg = config or load_config()
    if not cfg.get('enabled', True):
        return None
    site = {**(cfg.get('site') or {}), **{k: v for k, v in (site or {}).items() if v}}
    if not site.get('url'):
        raise ArdError('site.url is required')
    if not allow_local:
        _check_public_url(site['url'], 'site', False)
    ctx = {'publisher': publisher_for(site['url'], cfg.get('publisher')), 'site_url': site['url'].rstrip('/'),
           'site_name': site.get('name'), 'updated': _iso(updated), 'allow_local': allow_local}

    auto = cfg.get('autoDiscover') or {}
    res = {k: list((resources or {}).get(k) or []) if auto.get(k, True) else [] for k in ('mcp', 'openapi', 'a2a', 'skills')}
    for k in res:
        res[k] += list(cfg.get(k) or [])
    builders = {'mcp': mcp_entry, 'openapi': openapi_entry, 'a2a': a2a_entry, 'skills': skill_entry}
    entries = [(kind, builders[kind](r, ctx)) for kind in ('mcp', 'openapi', 'a2a', 'skills') for r in res[kind]]
    entries += [('custom', custom_entry(r, ctx)) for r in cfg.get('entries') or []]

    # Overrides: by kind ("mcp", "openapi", …) or by identifier; values replace generated terms.
    overrides = cfg.get('overrides') or {}
    for kind, e in entries:
        for key in (kind, e['identifier']):
            for k, v in (overrides.get(key) or {}).items():
                if v is None:
                    e.pop(k, None)
                else:
                    e[k] = v

    seen = set()
    out = []
    for _, e in entries:
        if e['identifier'] in seen:
            raise ArdError(f"duplicate identifier {e['identifier']}")
        seen.add(e['identifier'])
        _scan_secrets(e, e['identifier'])
        errs, _ = check_entry(e)
        if errs:
            raise ArdError('; '.join(errs))
        out.append(e)
    if not out:
        return None                                      # never publish an empty placeholder manifest
    host = _clean({'displayName': site.get('name'), 'identifier': ctx['site_url'], 'documentationUrl': site.get('documentationUrl'),
                   'description': site.get('description')})
    return {'host': host, 'entries': out}


def _read_json(path):
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def _iso(d):
    if not d:
        return None
    d = str(d)
    return d if 'T' in d else f'{d}T00:00:00Z'


# ----------------------------------------------------------------------------- validation

def check_entry(e):
    """Dependency-free checks mirroring the conformance tool: returns (errors, warnings)."""
    errs, warns = [], []
    label = e.get('displayName') or e.get('identifier') or '?'
    if not isinstance(e.get('identifier'), str) or not URN_RE.match(e['identifier']):
        errs.append(f"[{label}] identifier {e.get('identifier')!r} is not urn:air:<publisher>:[<namespace>:]<name>")
    if not e.get('displayName'):
        errs.append(f'[{label}] displayName is required')
    if not isinstance(e.get('type'), str) or not MEDIA_TYPE_RE.fullmatch(e['type']):
        errs.append(f"[{label}] type {e.get('type')!r} is not a valid media type")
    if ('url' in e) == ('data' in e):
        errs.append(f'[{label}] exactly one of url / data is required')
    q = e.get('representativeQueries')
    if not isinstance(q, list) or not 2 <= len(q) <= 5:
        warns.append(f'[{label}] representativeQueries should contain 2–5 queries')
    return errs, warns


def validate_manifest(manifest):
    """Full validation: official JSON Schema (if the jsonschema package is installed), conformance rules,
    MCP Server Card schema for inline cards. Returns (errors, warnings, notes)."""
    errs, warns, notes = [], [], []
    if not isinstance(manifest, dict) or not isinstance(manifest.get('entries'), list):
        return ["manifest must be an object with an 'entries' array"], warns, notes
    for e in manifest['entries']:
        a, b = check_entry(e if isinstance(e, dict) else {})
        errs += a
        warns += b
    try:
        import jsonschema
    except ImportError:
        notes.append('jsonschema not installed — JSON Schema validation skipped (pip install jsonschema)')
        return errs, warns, notes
    schema = _read_json(ARD_SCHEMA)
    for name, inst in [('ArdManifest', manifest)] + [('ArdEntry', e) for e in manifest['entries']]:
        s = dict(schema, **{'$ref': f'#/$defs/{name}'})
        for err in jsonschema.Draft202012Validator(s, format_checker=jsonschema.FormatChecker()).iter_errors(inst):
            errs.append(f"{name}: {err.message} at {'/'.join(map(str, err.absolute_path)) or '(root)'}")
    card_schema = dict(_read_json(SERVER_CARD_SCHEMA), **{'$ref': '#/$defs/ServerCard'})
    for e in manifest['entries']:
        if e.get('type') == TYPE_MCP and isinstance(e.get('data'), dict):
            for err in jsonschema.Draft7Validator(card_schema).iter_errors(e['data']):
                errs.append(f"[{e.get('displayName')}] MCP Server Card: {err.message} at {'/'.join(map(str, err.absolute_path)) or '(root)'}")
    return errs, warns, notes


def run_conformance(path):
    """Run the official ARD conformance tool (vendored, unmodified). Returns (exit_code, output)."""
    p = subprocess.run([sys.executable, CONFORMANCE_TOOL, 'manifest', path], capture_output=True, text=True)
    return p.returncode, re.sub(r'\x1b\[[0-9;]*m', '', p.stdout + p.stderr)


def check_urls(manifest, timeout=15):
    """Fetch every advertised url (and inline MCP endpoint is reported, not called). Returns list of problems."""
    problems = []
    for e in manifest.get('entries', []):
        if 'url' not in e:
            continue
        try:
            req = urllib.request.Request(e['url'], headers={'User-Agent': 'agentic-web-ard-check/1.0', 'Accept': '*/*'})
            with urllib.request.urlopen(req, timeout=timeout) as r:
                if r.status != 200:
                    problems.append(f"{e['identifier']}: {e['url']} → HTTP {r.status}")
        except urllib.error.HTTPError as x:
            problems.append(f"{e['identifier']}: {e['url']} → HTTP {x.code}")
        except Exception as x:  # noqa: BLE001 — report any network failure
            problems.append(f"{e['identifier']}: {e['url']} → {x}")
    return problems


# ----------------------------------------------------------------------------- CLI

def _cli(argv):
    import argparse
    ap = argparse.ArgumentParser(prog='ard.py', description='Build or validate an ARD manifest (/.well-known/ard.json).')
    sub = ap.add_subparsers(dest='cmd', required=True)
    b = sub.add_parser('build', help='build a manifest from an ard.config.json (standalone projects)')
    b.add_argument('--config', required=True)
    b.add_argument('--out', help='write here (default: stdout)')
    b.add_argument('--site-url', help='override site.url (per environment / domain)')
    b.add_argument('--allow-local', action='store_true', help='allow http:// and local hosts (development only)')
    v = sub.add_parser('validate', help='validate a manifest file or URL')
    v.add_argument('source')
    v.add_argument('--check-urls', action='store_true', help='also fetch every advertised url')
    a = ap.parse_args(argv)

    if a.cmd == 'build':
        cfg = load_config(a.config)
        site = dict(cfg.get('site') or {})
        if a.site_url:
            site['url'] = a.site_url
        try:
            m = build_manifest(site, None, cfg, updated=cfg.get('updatedAt'), allow_local=a.allow_local)
        except ArdError as x:
            print(f'ARD build failed: {x}', file=sys.stderr)
            return 1
        if m is None:
            print('ARD disabled or no resources configured — no manifest written.', file=sys.stderr)
            if a.out and os.path.exists(a.out):
                os.remove(a.out)
            return 0
        text = json.dumps(m, indent=2, ensure_ascii=False) + '\n'
        if a.out:
            os.makedirs(os.path.dirname(os.path.abspath(a.out)), exist_ok=True)
            with open(a.out, 'w', encoding='utf-8') as f:
                f.write(text)
            print(f"{len(m['entries'])} entries → {a.out}", file=sys.stderr)
        else:
            sys.stdout.write(text)
        return 0

    src = a.source
    tmp = None
    if re.match(r'^https?://', src):
        req = urllib.request.Request(src, headers={'User-Agent': 'agentic-web-ard-check/1.0', 'Accept': 'application/json'})
        with urllib.request.urlopen(req, timeout=20) as r:
            ctype = r.headers.get('Content-Type', '')
            body = r.read().decode('utf-8')
            print(f'GET {src} → HTTP {r.status}, Content-Type: {ctype}')
            if 'json' not in ctype:
                print('  warning: Content-Type should be application/json')
        import tempfile
        tmp = tempfile.NamedTemporaryFile('w', suffix='.json', delete=False, encoding='utf-8')
        tmp.write(body)
        tmp.close()
        src = tmp.name
    try:
        manifest = _read_json(src)
        errs, warns, notes = validate_manifest(manifest)
        code, out = run_conformance(src)
    finally:
        if tmp:
            os.unlink(tmp.name)
    summary = [ln for ln in out.splitlines() if 'CONFORMANCE STATUS' in ln or 'Validated with' in ln]
    print('Official conformance tool: ' + ' | '.join(s.strip() for s in summary))
    for n in notes:
        print('note: ' + n)
    for w in warns:
        print('warning: ' + w)
    for x in errs:
        print('ERROR: ' + x)
    problems = check_urls(manifest) if a.check_urls else []
    for p in problems:
        print('URL ERROR: ' + p)
    ok = not errs and code == 0 and not problems
    print(f"{'VALID' if ok else 'INVALID'}: {len(manifest.get('entries', []))} entries, {len(errs)} errors, {len(warns)} warnings")
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(_cli(sys.argv[1:]))
