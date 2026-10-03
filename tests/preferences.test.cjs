'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const vm = require('node:vm');
const {spawn} = require('node:child_process');

test('real PHP routes persist connection settings, protect relay, and preserve the theme editor', async () => {
  const folder = fs.mkdtempSync(path.join(os.tmpdir(),'ceol-http-'));
  const prefsFile = path.join(folder,'denon-ceol-preferences.json');
  for (const filename of ['index.php','localwifi.php']) fs.copyFileSync(path.join(__dirname,'..',filename),path.join(folder,filename));
  fs.writeFileSync(prefsFile,JSON.stringify({schema:1,revision:4,activeTheme:'midnight',activeView:'mini',denonIp:'',useHttpFallback:true,customThemes:[]}));
  const port = await new Promise(resolve=>{const probe=net.createServer();probe.listen(0,'127.0.0.1',()=>{const port=probe.address().port;probe.close(()=>resolve(port));});});
  const origin = 'http://127.0.0.1:'+port;
  const server = spawn(process.env.PHP_BINARY || 'php',['-n','-d','session.save_path='+folder,'-S','127.0.0.1:'+port,'-t',folder],{stdio:['ignore','ignore','pipe']});
  let logs='',cookie='',csrf;
  server.stderr.on('data',chunk=>{logs+=chunk;});
  const exited = new Promise(resolve=>server.once('exit',resolve));
  try {
    let response;
    for(let attempt=0;attempt<50;attempt++) {
      try { response=await fetch(origin+'/index.php');break; }
      catch(error) { await new Promise(resolve=>setTimeout(resolve,20)); }
    }
    assert.ok(response,'PHP server did not start: '+logs);
    cookie=response.headers.get('set-cookie').split(';')[0];
    const html=await response.text();
    const boot=JSON.parse(html.match(/const bootData = (.+);/)[1]);
    csrf=boot.csrf;
    assert.equal(boot.preferences.connectionMode,'relay');
    async function post(action, data={}, token=csrf) {
      const body=new URLSearchParams({ajax:'1',action,csrf:token,...data});
      const response=await fetch(origin+'/index.php',{method:'POST',headers:{Cookie:cookie},body});
      if(response.headers.get('set-cookie'))cookie=response.headers.get('set-cookie').split(';')[0];
      const result=await response.json();
      if(result.csrf)csrf=result.csrf;
      return {status:response.status,...result};
    }
    const denied=await post('save_ip',{ip:'192.168.50.182',connectionMode:'direct'},'wrong-token');
    assert.equal(denied.ok,false);
    assert.equal(denied.status,403);
    assert.equal(JSON.parse(fs.readFileSync(prefsFile)).denonIp,'');
    const saved=await post('save_ip',{ip:'192.168.50.182',connectionMode:'direct',directScheme:'https',directPort:'8443',directReadback:'1'});
    assert.equal(saved.ok,true);
    assert.equal(saved.preferences.connectionMode,'direct');
    assert.equal(saved.preferences.directPort,8443);
    assert.equal(saved.preferences.activeTheme,'midnight');
    assert.equal(saved.preferences.useHttpFallback,true);
    const beforeInvalid=fs.readFileSync(prefsFile,'utf8');
    const invalid=await post('save_ip',{ip:'192.168.50.183',connectionMode:'direct',directPort:'65536'});
    assert.equal(invalid.ok,false);
    assert.equal(fs.readFileSync(prefsFile,'utf8'),beforeInvalid); // No partial IP/mode/revision write.
    for(const action of ['command','status','test','volume_set','favorite']) {
      const blocked=await post(action,{command:'PWON',value:'20'});
      assert.equal(blocked.ok,false);
      assert.equal(blocked.status,409);
      assert.match(blocked.error,/No PHP-to-Denon request was made/);
    }
    // Use the real built-in theme data, not a second test-only theme schema.
    const context=vm.createContext({document:{querySelectorAll:()=>[]}});
    const script=html.split('<script>')[1].split('</script>')[0];
    vm.runInContext(script.slice(0,script.indexOf("    $('checkUpdatesButton').addEventListener")),context);
    const theme=JSON.parse(vm.runInContext('JSON.stringify(presets[0])',context));
    theme.id='custom-route-test';theme.name='Route test';
    assert.equal((await post('editor_setup',{password:'test-admin-password'})).ok,true);
    const themeSaved=await post('skin_save',{theme:JSON.stringify(theme),revision:String(saved.preferences.revision)});
    assert.equal(themeSaved.ok,true,themeSaved.error);
    assert.equal(themeSaved.preferences.customThemes.length,1);
    assert.equal(themeSaved.preferences.connectionMode,'direct');
    assert.equal(themeSaved.preferences.directScheme,'https');
    assert.equal(themeSaved.preferences.directPort,8443);
    assert.equal(themeSaved.preferences.directReadback,true);
    const relay=await post('save_ip',{ip:'192.168.50.183',connectionMode:'relay'});
    assert.equal(relay.ok,true);
    assert.equal(relay.preferences.customThemes[0].id,theme.id);
    assert.equal(relay.preferences.activeTheme,theme.id);
    assert.equal(relay.preferences.directPort,8443); // Direct choices retained while in relay mode.
    const badCommand=await post('command',{command:'BAD COMMAND'});
    assert.match(badCommand.error,/Command not allowed/); // Original relay validation still reached.
    const reload=await fetch(origin+'/index.php'); // A new session reads the shared preferences.
    const reloadedBoot=JSON.parse((await reload.text()).match(/const bootData = (.+);/)[1]);
    assert.equal(reloadedBoot.preferences.denonIp,'192.168.50.183');
    assert.equal(reloadedBoot.preferences.activeTheme,theme.id);
    const legacy=await fetch(origin+'/localwifi.php',{redirect:'manual'});
    assert.equal(legacy.status,302);
    assert.equal(legacy.headers.get('location'),'index.php?connection=direct');
    assert.equal(JSON.parse(fs.readFileSync(prefsFile)).connectionMode,'relay');
  } finally {
    server.kill();await exited;fs.rmSync(folder,{recursive:true,force:true});
  }
});
