#!/usr/bin/env python3
"""Fail if any component's version differs from the repo VERSION file.  Usage: python3 tools/check_versions.py"""
import json, os, re, sys, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
want = open(os.path.join(ROOT, 'VERSION')).read().strip()
def rd(p): return open(os.path.join(ROOT, p), encoding='utf-8').read()
def js(p): return json.loads(rd(p))

found = {
    'ranatec-api.php header (Version:)': re.search(r'^\s*\*\s*Version:\s*(\S+)', rd('ranatec-api/ranatec-api.php'), re.M).group(1),
    'ranatec-api.php RANATEC_API_VERSION': re.search(r"define\('RANATEC_API_VERSION',\s*'([^']+)'\)", rd('ranatec-api/ranatec-api.php')).group(1),
    'ranatec-api/openapi.json info.version': js('ranatec-api/openapi.json')['info']['version'],
    'web-root/openapi.json info.version': js('web-root/openapi.json')['info']['version'],
    'ranatec-api/public/api-catalog.json X-package-version': js('ranatec-api/public/api-catalog.json').get('X-package-version'),
    'web-root/api-catalog.json X-package-version': js('web-root/api-catalog.json').get('X-package-version'),
    'agent-page.html generator': (re.search(r'content="Ranatec Agentic Web package (\S+) ', rd('ranatec-api/public/agent-page.html')) or [None, None])[1],
    'ranatec-mcp/package.json': js('ranatec-mcp/package.json')['version'],
    'ranatec-mcp/package-lock.json': js('ranatec-mcp/package-lock.json')['version'],
    'ranatec-mcp/package-lock.json packages[""]': js('ranatec-mcp/package-lock.json')['packages']['']['version'],
}
for f in sorted(glob.glob(os.path.join(ROOT, 'ranatec-api/data/*.json'))):
    found[f'data/{os.path.basename(f)} meta.version'] = json.load(open(f))['meta']['version']
if 'const VERSION = "' in rd('ranatec-mcp/src/index.ts'):
    found['ranatec-mcp/src/index.ts hard-coded VERSION'] = 'hard-coded'

bad = {k: v for k, v in found.items() if v != want}
for k, v in found.items():
    print(f"{'ok ' if v == want else 'BAD'} {k}: {v}")
print(f"\nVERSION file: {want} — {'all consistent' if not bad else str(len(bad)) + ' mismatch(es)'}")
sys.exit(1 if bad else 0)
