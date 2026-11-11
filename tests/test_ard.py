#!/usr/bin/env python3
"""ARD (Agentic Resource Discovery) tests for the shared core (tools/ard.py) and the generated manifest.

Run: python3 -m unittest tests/test_ard.py      (also run by tests/run-all.sh)
Uses the official validators vendored in tools/vendor/ (ARD conformance tool + JSON Schema, MCP Server Card schema).
"""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, 'tools'))
import ard  # noqa: E402

MANIFEST_FILE = os.path.join(ROOT, 'ranatec-api', 'public', 'ard.json')
WEB_COPY = os.path.join(ROOT, 'web-root', '.well-known', 'ard.json')


def rd(path):
    with open(path, encoding='utf-8') as f:
        return f.read()


def rj(path):
    return json.loads(rd(path))


def wj(obj, path):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(obj, f)


PLUGIN = rd(os.path.join(ROOT, 'ranatec-api', 'ranatec-api.php'))


def sample(site_url='https://example.com', **over):
    """A package-shaped resource set for an arbitrary website (no Ranatec data)."""
    base = site_url.rstrip('/')
    res = {
        'mcp': [{'name': 'example-mcp', 'title': 'Example MCP server', 'endpoint': base + '/mcp', 'version': '2.0.0',
                 'description': 'Example tools.', 'tools': [{'name': 'search'}, {'name': 'get_item'}],
                 'queries': ['find an item', 'search the catalogue', 'get item details']}],
        'openapi': [{'url': '/openapi.json', 'title': 'Example API', 'version': '2.0.0', 'operations': ['listItems'],
                     'queries': ['list all items', 'get item by id']}],
    }
    res.update(over)
    return res


def build(site_url='https://example.com', resources=None, config=None, **kw):
    cfg = ard.load_config()
    cfg.update(config or {})
    return ard.build_manifest({'url': site_url, 'name': 'Example Ltd', 'description': 'An example.'},
                              sample(site_url) if resources is None else resources, cfg, updated='2026-11-05', **kw)


def conformance(manifest):
    with tempfile.NamedTemporaryFile('w', suffix='.json', delete=False) as f:
        json.dump(manifest, f)
    try:
        return ard.run_conformance(f.name)
    finally:
        os.unlink(f.name)


class GeneratedManifest(unittest.TestCase):
    """The manifest the package ships (ranatec-api/public/ard.json, web-root copy)."""

    @classmethod
    def setUpClass(cls):
        cls.m = rj(MANIFEST_FILE)

    def test_01_official_json_schema(self):
        errors, warnings, notes = ard.validate_manifest(self.m)
        self.assertNotIn('jsonschema not installed — JSON Schema validation skipped (pip install jsonschema)', notes,
                         'install jsonschema so the official schema is enforced')
        self.assertEqual(errors, [])
        self.assertEqual(warnings, [])

    def test_02_official_conformance_tool_no_errors_no_warnings(self):
        code, out = ard.run_conformance(MANIFEST_FILE)
        self.assertEqual(code, 0, out)
        self.assertIn('0 critical specification errors and 0 warnings', out, out)

    def test_02b_identifiers_and_media_types(self):
        for e in self.m['entries']:
            self.assertRegex(e['identifier'], ard.URN_RE)
            self.assertTrue(e['identifier'].startswith('urn:air:ranatec.com:'), e['identifier'])
            self.assertRegex(e['type'], ard.MEDIA_TYPE_RE)
            self.assertNotEqual(e['type'], 'application/mcp-server+json', 'deprecated by ADR-0008')
            self.assertTrue(2 <= len(e['representativeQueries']) <= 5)
        self.assertEqual(len({e['identifier'] for e in self.m['entries']}), len(self.m['entries']))

    def test_04_expected_resources(self):
        by_type = {e['type']: e for e in self.m['entries']}
        self.assertEqual(set(by_type), {ard.TYPE_MCP, ard.TYPE_OPENAPI}, 'only resources that exist: MCP server + OpenAPI API')
        mcp = by_type[ard.TYPE_MCP]
        card = mcp['data']
        endpoint = PLUGIN.split("define('RANATEC_MCP_URL', '")[1].split("'")[0]
        self.assertEqual(card['remotes'], [{'type': 'streamable-http', 'url': endpoint}])
        self.assertEqual(card['$schema'], ard.SERVER_CARD_SCHEMA_URI)
        self.assertEqual(card['version'], rj(os.path.join(ROOT, 'ranatec-mcp', 'package.json'))['version'])
        self.assertIn('request_quote', mcp['capabilities'])
        spec = rj(os.path.join(ROOT, 'ranatec-api', 'openapi.json'))
        api = by_type[ard.TYPE_OPENAPI]
        self.assertEqual(api['capabilities'], [op['operationId'] for ops in spec['paths'].values() for op in ops.values()])
        self.assertEqual(api['version'], spec['info']['version'])

    def test_05_urls_generated_from_configuration(self):
        site = PLUGIN.split("define('RANATEC_API_SITE', '")[1].split("'")[0]
        api = next(e for e in self.m['entries'] if e['type'] == ard.TYPE_OPENAPI)
        self.assertEqual(api['url'], site + '/openapi.json')
        self.assertEqual(self.m['host']['identifier'], site)

    def test_08_web_root_copy_identical(self):
        self.assertEqual(rd(WEB_COPY), rd(MANIFEST_FILE))

    def test_10_no_secrets_or_internal_endpoints(self):
        text = json.dumps(self.m).lower()
        for needle in ('x-ranatec-mcp-key', 'ranatec_mcp_key', 'mcp_key', 'password', 'secret', 'bearer', 'localhost',
                       '127.0.0.1', '/wp-admin', '/wp-json', 'render.com/dashboard', 'onrender.com/health'):
            self.assertNotIn(needle, text)
        for e in self.m['entries']:
            for u in [e.get('url')] + [r['url'] for r in (e.get('data') or {}).get('remotes', [])]:
                if u:
                    self.assertTrue(u.startswith('https://'), u)


class Core(unittest.TestCase):
    """tools/ard.py as a reusable, website-independent library."""

    def test_06_any_domain_and_environment(self):
        for site, pub in [('https://example.com', 'example.com'), ('https://www.shop.example.co.uk/', 'shop.example.co.uk'),
                          ('https://staging.example.org', 'staging.example.org')]:
            m = build(site)
            self.assertEqual(conformance(m)[0], 0)
            self.assertEqual(ard.validate_manifest(m)[0], [])
            for e in m['entries']:
                self.assertTrue(e['identifier'].startswith(f'urn:air:{pub}:'), e['identifier'])
            api = next(e for e in m['entries'] if e['type'] == ard.TYPE_OPENAPI)
            self.assertEqual(api['url'], site.rstrip('/') + '/openapi.json', 'relative url resolved against the site')
        # A fixed publisher keeps identifiers stable across environments (URN naming guide, scenario C).
        prod = build('https://example.com')
        stage = build('https://staging.example.com', config={'publisher': 'example.com'})
        self.assertEqual([e['identifier'] for e in prod['entries']], [e['identifier'] for e in stage['entries']])
        # Local development: allowed only explicitly, and never with a localhost publisher.
        with self.assertRaises(ard.ArdError):
            build('http://127.0.0.1:8080')
        local = build('http://127.0.0.1:8080', allow_local=True)
        self.assertTrue(all(e['identifier'].startswith('urn:air:agent.localhost:') for e in local['entries']))

    def test_07_disabled(self):
        self.assertIsNone(build(config={'enabled': False}))

    def test_07b_disabled_build_removes_file_and_references(self):
        tmp = tempfile.mkdtemp()
        try:
            for d in ('tools', 'ranatec-api', 'web-root'):
                shutil.copytree(os.path.join(ROOT, d), os.path.join(tmp, d))
            os.makedirs(os.path.join(tmp, 'ranatec-mcp', 'src'))
            shutil.copy(os.path.join(ROOT, 'ranatec-mcp', 'src', 'index.ts'), os.path.join(tmp, 'ranatec-mcp', 'src'))
            shutil.copy(os.path.join(ROOT, 'ranatec-mcp', 'package.json'), os.path.join(tmp, 'ranatec-mcp'))
            shutil.copy(os.path.join(ROOT, 'VERSION'), tmp)
            wj({'enabled': False}, os.path.join(tmp, 'ard.config.json'))
            subprocess.run([sys.executable, os.path.join(tmp, 'tools', 'build_static.py')], check=True, capture_output=True)
            self.assertFalse(os.path.exists(os.path.join(tmp, 'ranatec-api', 'public', 'ard.json')))
            self.assertFalse(os.path.exists(os.path.join(tmp, 'web-root', '.well-known', 'ard.json')))
            self.assertNotIn('rel="ard"', rd(os.path.join(tmp, 'ranatec-api', 'public', 'agent-page.html')))
            self.assertNotIn('data-ard', rd(os.path.join(tmp, 'ranatec-api', 'public', 'agent-page.html')))
            self.assertNotIn('Agentmap:', rd(os.path.join(tmp, 'web-root', 'robots-addition.txt')))
            # …and the default (no config file) publishes it again with every reference.
            os.remove(os.path.join(tmp, 'ard.config.json'))
            subprocess.run([sys.executable, os.path.join(tmp, 'tools', 'build_static.py')], check=True, capture_output=True)
            self.assertTrue(os.path.exists(os.path.join(tmp, 'web-root', '.well-known', 'ard.json')))
            self.assertIn('rel="ard"', rd(os.path.join(tmp, 'ranatec-api', 'public', 'agent-page.html')))
            self.assertIn('<li data-ard><a href="https://ranatec.com/.well-known/ard.json">ARD manifest</a>', rd(os.path.join(tmp, 'ranatec-api', 'public', 'agent-page.html')))
            self.assertIn('Agentmap: https://ranatec.com/.well-known/ard.json', rd(os.path.join(tmp, 'web-root', 'robots-addition.txt')))
        finally:
            shutil.rmtree(tmp)

    def test_09_missing_optional_metadata(self):
        # No site name/description, no queries/tools/version: still valid; queries fall back gracefully.
        m = ard.build_manifest({'url': 'https://example.com'}, {'openapi': [{'url': '/openapi.json'}]}, ard.load_config())
        self.assertEqual(ard.validate_manifest(m)[0], [])
        self.assertEqual(conformance(m)[0], 0)
        m2 = build(resources={'openapi': [{'url': '/openapi.json', 'title': 'API'}]})
        self.assertEqual(len(m2['entries'][0]['representativeQueries']), 2)
        # Nothing to publish → no manifest at all (never an empty placeholder).
        self.assertIsNone(build(resources={}))
        self.assertIsNone(build(config={'autoDiscover': {'mcp': False, 'openapi': False}}))

    def test_04b_only_real_resources_and_custom_entries(self):
        m = build(resources={'openapi': [{'url': '/openapi.json', 'title': 'API'}]})
        self.assertEqual([e['type'] for e in m['entries']], [ard.TYPE_OPENAPI], 'no MCP/A2A/skill entry unless configured')
        m = build(config={
            'a2a': [{'name': 'support-agent', 'cardUrl': '/.well-known/agent-card.json', 'description': 'Support agent', 'skills': ['faq']}],
            'skills': [{'name': 'quote-helper', 'url': '/skills/quote/SKILL.md', 'format': 'md'}],
            'entries': [{'displayName': 'Partner catalog', 'type': 'application/ai-catalog+json', 'url': 'https://partner.example.net/.well-known/ard.json',
                         'namespace': 'catalog', 'representativeQueries': ['partner tools', 'find partner agents']}],
            'overrides': {'mcp': {'tags': ['custom-tag']}, 'urn:air:example.com:api:example-api': {'description': 'Overridden.'}},
        })
        types = [e['type'] for e in m['entries']]
        self.assertEqual(types, [ard.TYPE_MCP, ard.TYPE_OPENAPI, ard.TYPE_A2A, 'application/agent-skills+md', 'application/ai-catalog+json'])
        self.assertEqual(m['entries'][0]['tags'], ['custom-tag'])
        self.assertEqual(m['entries'][1]['description'], 'Overridden.')
        self.assertEqual(m['entries'][2]['url'], 'https://example.com/.well-known/agent-card.json')
        self.assertEqual(m['entries'][4]['identifier'], 'urn:air:example.com:catalog:partner-catalog')
        code, out = conformance(m)
        self.assertEqual(code, 0, out)
        self.assertIn('and 0 warnings', out, out)

    def test_04d_example_config_is_valid(self):
        cfg = ard.load_config(os.path.join(ROOT, 'ard.config.example.json'))
        self.assertTrue(cfg['enabled'])
        m = ard.build_manifest({'url': 'https://example.com', 'name': 'Example Ltd'}, sample(), cfg)
        self.assertEqual(ard.validate_manifest(m)[:2], ([], []))
        self.assertEqual(len(m['entries'][0]['representativeQueries']), 3)

    def test_04c_hosted_server_card(self):
        res = sample()
        res['mcp'][0]['cardUrl'] = 'https://example.com/mcp/server-card'
        e = build(resources=res)['entries'][0]
        self.assertEqual(e['url'], 'https://example.com/mcp/server-card')
        self.assertNotIn('data', e)

    def test_10_rejects_secrets_and_internal_urls(self):
        bad = [
            {'entries': [{'displayName': 'x', 'type': 'application/json', 'url': 'https://example.com/a?api_key=123'}]},
            {'entries': [{'displayName': 'x', 'type': 'application/json', 'url': 'https://user:pw@example.com/a'}]},
            {'entries': [{'displayName': 'x', 'type': 'application/json', 'url': 'http://10.0.0.5/internal.json'}]},
            {'entries': [{'displayName': 'x', 'type': 'application/json', 'url': 'https://example.com/a', 'metadata': {'apiToken': 'abc'}}]},
            {'entries': [{'displayName': 'x', 'type': 'not a media type', 'url': 'https://example.com/a'}]},
            {'entries': [{'displayName': 'x', 'type': 'application/json', 'url': 'https://example.com/a', 'data': {}}]},
            {'entries': [{'identifier': 'urn:air:localhost', 'displayName': 'x', 'type': 'application/json', 'url': 'https://example.com/a'}]},
        ]
        for cfg in bad:
            with self.assertRaises(ard.ArdError, msg=json.dumps(cfg)):
                build(config=cfg)
        res = sample()
        res['mcp'][0]['endpoint'] = 'http://localhost:3000/mcp'
        with self.assertRaises(ard.ArdError):
            build(resources=res)

    def test_server_card_matches_official_schema_limits(self):
        res = sample()
        res['mcp'][0]['description'] = 'word ' * 60
        card = build(resources=res)['entries'][0]['data']
        self.assertLessEqual(len(card['description']), 100)
        self.assertRegex(card['name'], r'^[a-zA-Z0-9.-]+/[a-zA-Z0-9._-]+$')
        self.assertEqual(card['name'], 'com.example/example-mcp')
        self.assertEqual(ard.validate_manifest(build(resources=res))[0], [])

    def test_cli_build_and_validate(self):
        tmp = tempfile.mkdtemp()
        try:
            cfg = {'site': {'url': 'https://example.com', 'name': 'Example Ltd'},
                   'openapi': [{'url': '/openapi.json', 'title': 'Example API', 'queries': ['list items', 'get an item']}]}
            wj(cfg, os.path.join(tmp, 'ard.config.json'))
            out = os.path.join(tmp, 'public', '.well-known', 'ard.json')
            r = subprocess.run([sys.executable, os.path.join(ROOT, 'tools', 'ard.py'), 'build', '--config', os.path.join(tmp, 'ard.config.json'), '--out', out], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, r.stderr)
            v = subprocess.run([sys.executable, os.path.join(ROOT, 'tools', 'ard.py'), 'validate', out], capture_output=True, text=True)
            self.assertEqual(v.returncode, 0, v.stdout)
            self.assertIn('VALID: 1 entries, 0 errors, 0 warnings', v.stdout)
            # Disabled → the CLI removes a previously written manifest.
            wj(dict(cfg, enabled=False), os.path.join(tmp, 'ard.config.json'))
            subprocess.run([sys.executable, os.path.join(ROOT, 'tools', 'ard.py'), 'build', '--config', os.path.join(tmp, 'ard.config.json'), '--out', out], check=True, capture_output=True)
            self.assertFalse(os.path.exists(out))
        finally:
            shutil.rmtree(tmp)


if __name__ == '__main__':
    unittest.main(verbosity=2)
