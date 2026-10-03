'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {spawnSync} = require('node:child_process');

const fixture = spawnSync(process.env.PHP_BINARY || 'php', ['-n', path.join(__dirname, 'connection_test.php'), '--boot'], {encoding:'utf8'});
assert.equal(fixture.status, 0, fixture.stderr || fixture.stdout);
const boot = JSON.parse(fixture.stdout);
const source = fs.readFileSync(path.join(__dirname, '../index.php'), 'utf8');
const script = source.split('<script>')[1].split('</script>')[0].replace(/<\?= json_encode\(\$ceolBoot[\s\S]*?\?>/, JSON.stringify(boot));
// Execute production functions, leaving page event binding/rendering to browser QA.
const functions = script.slice(0, script.indexOf("    $('checkUpdatesButton').addEventListener"));
new vm.Script(script); // Syntax-check the complete browser script as well.

function element() {
  const classes = new Set();
  return {value:'',textContent:'',hidden:true,disabled:false,title:'',dataset:{},
    classList:{toggle(name, on) { if (on) classes.add(name); else classes.delete(name); }, contains:name=>classes.has(name)},
    focus() {}, setAttribute() {}, querySelector() { return element(); }};
}
function harness(options = {}) {
  const elements = new Map(), calls = [], delays = [], intervals = [], permissionCalls = [];
  const get = id => { if (!elements.has(id)) elements.set(id, element()); return elements.get(id); };
  const document = {getElementById:get, querySelectorAll:()=>[],hidden:false};
  class BrowserRequest {}
  if (options.addressSpace) BrowserRequest.prototype.targetAddressSpace = 'unknown';
  const context = vm.createContext({document,location:{href:'https://remote.example/index.php',protocol:options.protocol || 'https:'},
    navigator:{permissions:{query:async query=>{permissionCalls.push(query.name);return options.permission?options.permission(query):{state:'granted'};}}},
    Request:BrowserRequest,FormData,AbortController,URL,TypeError,Error,
    setTimeout(fn, ms) { delays.push(ms);return setTimeout(fn, ms===6000?100:0); },clearTimeout,
    setInterval(fn, ms) { intervals.push({fn,ms});return intervals.length; },clearInterval() {},
    fetch:async (url, request)=>{
      calls.push({url,request});
      if (options.fetch) return options.fetch(url,request);
      return request.method==='POST'?{json:async()=>({ok:true,lines:[],state:{power:'on',powerLabel:'On',volumeNumber:12}})}:{type:'opaque',status:0,ok:false};
    }});
  vm.runInContext(functions, context);
  const run = code=>vm.runInContext(code, context);
  run(`preferences.connectionMode=${JSON.stringify(options.mode || 'direct')}; preferences.directReadback=${!!options.readback};`);
  return {run,get,calls,delays,intervals,permissionCalls,context,document};
}
const tick = ()=>new Promise(resolve=>setImmediate(resolve));

test('all existing remote actions route directly, including volume/favorite and probes', async () => {
  const h = harness();
  await h.run("sendCommand('  sianalogin  ')");
  await h.run('setVolume(23.9)');
  await h.run("favoriteGo({value:'7'})");
  await h.run('testConnection()');
  await h.run('refreshStatus(true)');
  assert.deepEqual(h.calls.map(c=>c.url), ['SIANALOGIN','MV23','FV07','PW%3F','PW%3F'].map(c=>'http://192.168.50.182:80/goform/formiPhoneAppDirect.xml?'+c));
  assert.ok(h.calls.every(c=>c.request.method==='GET' && c.request.mode==='no-cors' && c.request.credentials==='omit' && c.request.redirect==='error'));
  assert.equal(h.run('preferences.useHttpFallback'), true); // Saved relay option never overrides direct mode.
});

test('power/mute mapping is shared with PHP; dynamic commands and manual validation have boundaries', async () => {
  const h = harness();
  for (const [command, endpoint] of [['PWON','Power.xml?1+PowerOn'],['PWSTANDBY','Power.xml?1+PowerStandby'],['MUON','Mute.xml?1+MuteOn'],['MUOFF','Mute.xml?1+MuteOff']]) {
    await h.run(`sendCommand(${JSON.stringify(command)})`);
    assert.ok(h.calls.at(-1).url.endsWith('/goform/formiPhoneApp'+endpoint));
  }
  for (const command of ['MV00','MV60','FV01','FV50','TFAN105000',...boot.allowedCommands]) assert.equal(h.run(`validCommand(${JSON.stringify(command)})`),true);
  const count = h.calls.length;
  for (const command of ['MV61','FV00','FV51','TFAN12345','PWON&x=1','PWON\rMUOFF','toggle_power','arbitrary']) {
    await assert.rejects(h.run(`remoteRequest('command',{command:${JSON.stringify(command)}})`), /Command not allowed/);
  }
  assert.equal(h.calls.length,count);
});

test('opaque dispatch stays unconfirmed and never invents received status', async () => {
  const h = harness();
  h.document.querySelectorAll = selector=>selector==='[data-live="state"]'?[Object.assign(element(),{dataset:{key:'power'}})]:[];
  await h.run("sendCommand('PWON')");
  assert.equal(h.get('connText').textContent,'Direct · Unconfirmed');
  assert.equal(h.get('connDot').classList.contains('online'),false);
  assert.match(h.get('connectionNotice').textContent,/opaque.*unconfirmed/);
  assert.equal(h.run('liveState.power'),'on');
  assert.match(h.get('footerState').textContent,/delivery unconfirmed/);
  assert.equal(h.run('liveState.display.length'),0);
  h.run('startPolling()');
  await h.run('refreshStatus()');
  assert.equal(h.calls.length,1);
  assert.equal(h.intervals.length,0);
});

test('explicit CORS mode checks HTTP errors and confirms only an HTTP reply', async () => {
  const h = harness({readback:true,fetch:async()=>({type:'cors',status:200,ok:true})});
  await h.run("sendCommand('MUON')");
  assert.equal(h.calls[0].request.mode,'cors');
  assert.equal(h.get('connText').textContent,'Direct · HTTP replied');
  assert.match(h.get('connectionNotice').textContent,/estimates.*polling is off/);
  const bad = harness({readback:true,fetch:async()=>({type:'cors',status:404,ok:false})});
  await bad.run("sendCommand('MUON')");
  assert.match(bad.get('connectionNotice').textContent,/HTTP 404/);
  assert.equal(bad.run('liveState.mute'),null);
  assert.equal(bad.calls.length,1);
});

test('network/CORS/mixed-content failures report uncertainty, stop queued work, and never relay', async () => {
  let reject;
  const h = harness({readback:true,fetch:()=>new Promise((resolve,no)=>{reject=no;})});
  const first = h.run("sendCommand('MVUP')");
  const pending = h.run("sendCommand('MVDOWN')");
  await tick();
  reject(new TypeError('Failed to fetch'));
  await Promise.all([first,pending]);
  assert.equal(h.calls.length,1);
  assert.equal(h.run('preferences.connectionMode'),'direct');
  assert.match(h.get('connectionNotice').textContent,/mixed content.*CORS.*private-network.*unreachable/);
  assert.match(h.get('connectionNotice').textContent,/cannot distinguish.*not retried through PHP.*Pending commands stopped/);
  assert.equal(h.get('connectionNotice').classList.contains('error'),true);
});

test('direct timeout releases the queue and does not retry an uncertain command', async () => {
  const h = harness({fetch:(url,request)=>new Promise((resolve,reject)=>request.signal.addEventListener('abort',()=>reject(new Error('aborted'))))});
  await h.run("sendCommand('MUOFF')");
  assert.match(h.get('connectionNotice').textContent,/timed out after 6 seconds.*may have reached.*not retried/);
  assert.equal(h.run('busy'),false);
  assert.equal(h.calls.length,1);
});

test('denied network permissions prevent a send; unsupported names fall back compatibly', async () => {
  const h = harness({permission:async ({name})=>{if(name==='local-network')throw new TypeError('unsupported');return {state:'denied'};}});
  await h.run('testConnection()');
  assert.deepEqual(h.permissionCalls,['local-network','local-network-access']);
  assert.equal(h.calls.length,0);
  assert.match(h.get('connectionNotice').textContent,/access is denied/);
  const unsupported = harness({permission:async()=>{throw new TypeError('unsupported');}});
  await unsupported.run("sendCommand('MUOFF')");
  assert.equal(unsupported.calls.length,1);
});

test('validated direct protocol/port is honored and supported local-network annotation is used', async () => {
  const h = harness({addressSpace:true});
  h.run("preferences.directScheme='https';preferences.directPort=8443;");
  await h.run("sendCommand('NS9A')");
  assert.equal(h.calls[0].url,'https://192.168.50.182:8443/goform/formiPhoneAppDirect.xml?NS9A');
  assert.equal(h.calls[0].request.targetAddressSpace,'local');
  h.run("preferences.denonIp='127.0.0.1'");
  await h.run('testConnection()');
  assert.equal(h.calls[1].request.targetAddressSpace,'loopback');
  const invalid = harness();
  for (const change of ["preferences.denonIp='8.8.8.8'", "preferences.denonIp='192.168.0.999'", "preferences.denonIp='192.168.0.1';preferences.directPort=0", "preferences.directPort=80;preferences.directScheme='file'"]) {
    invalid.run(change);
    await assert.rejects(invalid.run("remoteRequest('command',{command:'PWON'})"));
  }
  assert.equal(invalid.calls.length,0);
});

test('relay keeps original PHP actions, CSRF, fallback selection, and status polling', async () => {
  const h = harness({mode:'relay'});
  await h.run("sendCommand('MUOFF')");
  await h.run('setVolume(22)');
  await h.run("favoriteGo({value:'50'})");
  await h.run('testConnection()');
  await h.run('refreshStatus(true)');
  assert.deepEqual(h.calls.map(c=>c.request.body.get('action')),['command','volume_set','favorite','test','status']);
  assert.ok(h.calls.every(c=>c.url==='https://remote.example/index.php' && c.request.method==='POST' && c.request.body.get('csrf')===boot.csrf));
  assert.equal(h.calls[0].request.body.get('httpFallback'),'1');
  assert.equal(h.get('connText').textContent,'Relay · Online');
  h.run('startPolling()');
  await tick();
  assert.equal(h.intervals.at(-1).ms,3000);
});

test('one queue serializes direct requests, coalesces sliders, and evaluates toggles at execution', async () => {
  let release, count=0;
  const h = harness({fetch:()=>++count===1?new Promise(resolve=>{release=resolve;}):Promise.resolve({type:'opaque',status:0})});
  const first = h.run("sendCommand('NS9A')");
  const sliderOld = h.run('setVolume(20)');
  const sliderNew = h.run('setVolume(30)');
  await tick();
  assert.equal(count,1);
  release({type:'opaque',status:0});
  await Promise.all([first,sliderOld,sliderNew]);
  assert.equal(count,2);
  assert.ok(h.calls[1].url.endsWith('?MV30'));
  const toggles = harness();
  await Promise.all([toggles.run("sendCommand(()=>liveState.power==='on'?'PWSTANDBY':'PWON')"),toggles.run("sendCommand(()=>liveState.power==='on'?'PWSTANDBY':'PWON')")]);
  assert.ok(toggles.calls[0].url.endsWith('1+PowerOn') && toggles.calls[1].url.endsWith('1+PowerStandby'));
  assert.ok(toggles.delays.includes(1000) && toggles.delays.includes(80));
});

test('saving a new connection cancels pending work and discards stale failures without changing themes', async () => {
  let rejectOld;
  const next = {...boot.preferences,connectionMode:'direct',denonIp:'192.168.50.183'};
  const h = harness({fetch:(url,request)=>request.method==='POST'?Promise.resolve({json:async()=>({ok:true,ip:next.denonIp,preferences:next})}):
    url.includes('.182:')?new Promise((resolve,reject)=>{rejectOld=reject;}):Promise.resolve({type:'opaque',status:0})});
  const old = h.run("sendCommand('PWON')");
  const pending = h.run("sendCommand('MUON')");
  await tick();
  h.get('denonIpInput').value=next.denonIp;h.get('connectionModeInput').value='direct';
  h.get('directSchemeInput').value='http';h.get('directPortInput').value='80';h.get('directReadbackInput').value='0';
  h.context.event={preventDefault(){},submitter:element()};
  await h.run('saveConnection(event)');
  rejectOld(new TypeError('old device failed'));
  await Promise.all([old,pending]);await tick();await new Promise(resolve=>setTimeout(resolve,10));
  assert.equal(h.calls.filter(c=>c.request.method==='GET').length,2); // Old command, new PW? probe.
  assert.ok(h.calls.at(-1).url.includes('.183:') && h.calls.at(-1).url.endsWith('PW%3F'));
  assert.equal(h.run('liveState.power'),'unknown');
  assert.doesNotMatch(h.get('connectionNotice').textContent,/old device|failed/i);
  assert.equal(h.run('preferences.activeTheme'),boot.preferences.activeTheme);
  assert.deepEqual(JSON.parse(h.run('JSON.stringify(preferences.customThemes)')),boot.preferences.customThemes);
});

test('connection dialogs restore saved choices and disable the relay widget in direct mode', () => {
  const h = harness();
  h.run('showConnectionSettings()');
  assert.equal(h.get('connectionModeInput').value,'direct');
  assert.equal(h.get('directSettings').hidden,false);
  const fallback = element();
  h.document.querySelectorAll = selector=>selector==='[data-live="fallback"]'?[fallback]:[];
  h.run('updateLiveDisplays()');
  assert.equal(fallback.disabled,true);
  assert.equal(fallback.checked,true);
  h.get('connectionModeInput').value='relay';h.run('renderConnectionHelp()');
  assert.equal(h.get('directPortInput').disabled,true);
});
