# Vendored validators (Apache-2.0)

Unmodified copies of the official validators, used by `tools/ard.py validate` and the test suite.
They are not loaded on any website.

| Directory | Upstream | Pinned commit | Files |
|---|---|---|---|
| `ard-spec/` | https://github.com/ards-project/ard-spec (ARD v0.91) | `f073b1ea9b01a5f068d0b0986426f2ea49fa30b2` (the same commit Lighthouse 13.6's `ard-schema` audit is ported from) | `conformance/bin/conformance-test`, `spec/schemas/ard-entry.schema.json`, `LICENSE` |
| `mcp-server-card/` | https://github.com/modelcontextprotocol/experimental-ext-server-card (SEP-2127, Final) | `526201bbc80231daa40ffcdecfc9da4e54e5dc93` | `schema.json` → `server-card.schema.json`, `LICENSE` |

The upstream directory layout of `ard-spec/` is kept so that `conformance-test` finds its schema
(`../../spec/schemas/ard-entry.schema.json`) without modification.

To update: copy the same files from a newer upstream commit, update the table, and run `bash tests/run-all.sh`.
