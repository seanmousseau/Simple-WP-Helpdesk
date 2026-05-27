#!/usr/bin/env bash
# Regenerate docs/openapi.json from docs/openapi.yaml.
#
# The YAML file is the canonical, human-edited source of truth for the public
# REST API contract. The JSON form is a committed build artifact served by
# `GET /swh/v1/openapi.json` (no runtime YAML parser dependency) and consumed
# by tools like Postman / Insomnia.
#
# CI runs this script and then `git diff --exit-code docs/openapi.json` to
# catch silent drift. Locally: `bash bin/openapi-sync.sh`.
#
# Requires Python 3 with PyYAML (already a transitive dep of `testing/`).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
YAML="$REPO_ROOT/docs/openapi.yaml"
JSON="$REPO_ROOT/docs/openapi.json"
# Also written into the plugin directory so single-plugin installs (release
# zip, WP plugin installs, the Docker test stack that mounts only
# simple-wp-helpdesk/) can serve the real spec from /openapi.json without
# falling back to the controller's minimal stub. See OpenApiController.
PLUGIN_JSON="$REPO_ROOT/simple-wp-helpdesk/openapi.json"

if [ ! -f "$YAML" ]; then
  echo "openapi-sync: $YAML not found" >&2
  exit 1
fi

python3 - <<PY
import json, sys
try:
    import yaml
except ImportError:
    sys.stderr.write("openapi-sync: PyYAML not installed. Run `pip install pyyaml` or use the testing venv.\n")
    sys.exit(2)
with open("$YAML", "r", encoding="utf-8") as f:
    spec = yaml.safe_load(f)
payload = json.dumps(spec, indent=2, sort_keys=False) + "\n"
for path in ("$JSON", "$PLUGIN_JSON"):
    with open(path, "w", encoding="utf-8") as f:
        f.write(payload)
print(f"openapi-sync: wrote {len(spec.get('paths', {}))} paths to {'$JSON'} and {'$PLUGIN_JSON'}")
PY
