#!/usr/bin/env bash
set -euo pipefail

# Invoked as the same unprivileged user as the native installer, only in ojs-ci.
php <<'PHP'
<?php
require 'tools/bootstrap.php';
new \PKP\cliTool\CommandLineTool(['omi-http-portability-fixture']);
if (getenv('OMI_NATIVE_INSTALL_TEST') !== '1'
    || realpath('.') !== getenv('OMI_NATIVE_TEST_APP')
    || \PKP\config\Config::getVar('database', 'name') !== 'ojs-ci'
    || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
    throw new RuntimeException('HTTP fixture requires the disposable non-root ojs-ci application');
}
$contextId = \Illuminate\Support\Facades\DB::table('journals')->where('path', 'omi-settings-fixture')->value('journal_id');
if (!$contextId) throw new RuntimeException('Native settings fixture journal is missing');
$plugin = new \APP\plugins\generic\studioIntegration\StudioIntegrationPlugin();
$plugin->updateSetting((int)$contextId, 'enabled', true, 'bool');
PHP

fixture="$(mktemp -d)"
server_pid=''
cleanup() {
  if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
  if [[ -f "$fixture/config.inc.php" ]]; then cp "$fixture/config.inc.php" config.inc.php; fi
  rm -rf "$fixture"
}
trap cleanup EXIT
cp config.inc.php "$fixture/config.inc.php"
python3 <<'PY'
import pathlib, re
config = pathlib.Path('config.inc.php')
text, count = re.subn(r'(?m)^allowed_hosts\s*=.*$',
    'allowed_hosts = \'["localhost", "127.0.0.1", "journal-a.example.invalid", "journal-b.example.invalid"]\'',
    config.read_text())
if count != 1:
    raise RuntimeError('Expected exactly one allowed_hosts setting in the disposable test config')
config.write_text(text)
PY
mkdir -p "$fixture/web/nested"
ln -s "$PWD" "$fixture/web/nested/journals"

for layout in root nested; do
  if [[ "$layout" == root ]]; then
    document_root="$PWD"
    prefix=''
    host='journal-a.example.invalid'
  else
    document_root="$fixture/web"
    prefix='/nested/journals'
    host='journal-b.example.invalid'
  fi
  # Bind only to loopback. Each job is an isolated runner with no public server.
  php -S 127.0.0.1:18765 -t "$document_root" >"$fixture/server.log" 2>&1 &
  server_pid=$!
  if ! python3 - "$prefix" "$host" <<'PY'
import json, pathlib, sys, time, urllib.error, urllib.request, xml.etree.ElementTree as ET
prefix, host = sys.argv[1:]
plugin = pathlib.Path('plugins/generic/studioIntegration')
expected_version = ET.parse(plugin / 'version.xml').findtext('release').removesuffix('.0')
def get(path):
    request = urllib.request.Request('http://127.0.0.1:18765' + prefix + path, headers={'Host': host})
    return urllib.request.urlopen(request, timeout=15)
for attempt in range(40):
    try:
        with get('/plugins/generic/studioIntegration/version.xml') as response:
            assert response.status == 200
        break
    except urllib.error.URLError:
        if attempt == 39: raise
        time.sleep(0.2)
with get('/index.php/omi-settings-fixture/api/v1/omi-integration') as response:
    assert response.status == 200
    body = json.load(response)
assert body['protocol'] == 'omi-integration/1', body
assert body['implementation']['version'] == expected_version, body
assert body['context']['path'] == 'omi-settings-fixture', body
assert 'launch' in body['capabilities'], body
for asset in ['js/studioIntegration.js', 'css/studioIntegration.css']:
    with get('/plugins/generic/studioIntegration/' + asset) as response:
        assert response.status == 200 and response.read() == (plugin / asset).read_bytes()
try:
    get('/index.php/omi-settings-fixture/api/v1/omi-integration/submission')
except urllib.error.HTTPError as error:
    assert error.code == 401, (error.code, error.read())
else:
    raise AssertionError('Unsigned submission access must remain forbidden')
print('Native HTTP API, static assets and protected submission route passed: ' + host + prefix)
PY
  then
    tail -n 70 "$fixture/server.log"
    exit 1
  fi
  kill "$server_pid"
  wait "$server_pid" 2>/dev/null || true
  server_pid=''
done
