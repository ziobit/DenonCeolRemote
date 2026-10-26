'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require('node:path').join(__dirname,'../index.php'),'utf8');
const script = source.split('<script>')[1].split('</script>')[0];

test('single-page session ownership and phone UI remain wired', () => {
  new vm.Script(script);
  assert.match(source,/const SESSION_TIMEOUT_SECONDS = 10;/);
  assert.match(source,/const SESSION_FILE = '\/tmp\/denon-ceol-session\.txt';/);
  assert.ok(source.includes("if (!preg_match('/^[0-9a-f]{32}$/', $page))"));
  assert.match(source,/const PAGE_ID = Array\.from\(crypto\.getRandomValues\(new Uint8Array\(16\)\)/);
  assert.match(script,/form\.append\('page', PAGE_ID\)/);
  assert.match(script,/navigator\.sendBeacon\(location\.href, form\)/);
  assert.match(script,/document\.addEventListener\('visibilitychange'/);
  assert.match(script,/window\.addEventListener\('pagehide', releaseSession\)/);
  assert.match(script,/if \(!document\.hidden\) startPolling\(\)/);
  assert.doesNotMatch(source,/session_start\(/);
});

test('light theme, phone tabs, and supported command boundaries are present', () => {
  assert.match(source,/color-scheme: light/);
  assert.match(source,/data-goto="remote"/);
  assert.match(source,/data-goto="sources"/);
  assert.match(source,/data-goto="browse"/);
  assert.match(source,/data-goto="volume"/);
  assert.match(source,/data-goto="tools"/);
  assert.match(source,/function is_allowed_denon_command/);
  assert.match(source,/\^MV\(\[0-5\]\[0-9\]\|60\)\$/);
  assert.match(source,/\^FV\(0\[1-9\]\|\[1-4\]\[0-9\]\|50\)\$/);
});
