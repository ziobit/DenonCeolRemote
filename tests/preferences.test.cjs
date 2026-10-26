'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn} = require('node:child_process');

test('PHP routes share the configured IP without opening a PHP session', async () => {
  const folder = fs.mkdtempSync(path.join(os.tmpdir(),'ceol-http-'));
  const ipFile = path.join(folder,'denon-ip.txt');
  for (const filename of ['index.php','localwifi.php']) fs.copyFileSync(path.join(__dirname,'..',filename),path.join(folder,filename));
  const port = await new Promise(resolve=>{const probe=net.createServer();probe.listen(0,'127.0.0.1',()=>{const port=probe.address().port;probe.close(()=>resolve(port));});});
  const origin = 'http://127.0.0.1:'+port;
  const server = spawn(process.env.PHP_BINARY || 'php',['-n','-S','127.0.0.1:'+port,'-t',folder],{stdio:['ignore','ignore','pipe']});
  let logs='';
  server.stderr.on('data',chunk=>{logs+=chunk;});
  const exited = new Promise(resolve=>server.once('exit',resolve));
  try {
    let response;
    for(let attempt=0;attempt<50;attempt++) {
      try { response=await fetch(origin+'/index.php');break; }
      catch(error) { await new Promise(resolve=>setTimeout(resolve,20)); }
    }
    assert.ok(response,'PHP server did not start: '+logs);
    assert.equal(response.headers.get('set-cookie'),null);
    const html=await response.text();
    assert.match(html,/192\.168\.23\.180/);
    assert.match(html,/const PAGE_ID = Array\.from\(crypto\.getRandomValues/);

    async function post(action, data={}) {
      const body=new URLSearchParams({ajax:'1',action,...data});
      const result=await fetch(origin+'/index.php',{method:'POST',body});
      return result.json();
    }
    const saved=await post('save_ip',{ip:'192.168.50.182'});
    assert.deepEqual(saved,{ok:true,ip:'192.168.50.182'});
    assert.equal(fs.readFileSync(ipFile,'utf8'),'192.168.50.182\n');
    const reloaded=await fetch(origin+'/index.php');
    assert.match(await reloaded.text(),/192\.168\.50\.182/);

    const invalid=await post('save_ip',{ip:'8.8.8.8'});
    assert.equal(invalid.ok,false);
    assert.equal(fs.readFileSync(ipFile,'utf8'),'192.168.50.182\n');
    const missingPage=await post('status',{page:'invalid'});
    assert.match(missingPage.error,/Missing or invalid page id/);

    const reset=await post('reset_ip');
    assert.deepEqual(reset,{ok:true,ip:'192.168.23.180'});
    assert.equal(fs.existsSync(ipFile),false);
    const legacy=await fetch(origin+'/localwifi.php',{redirect:'manual'});
    assert.equal(legacy.status,302);
    assert.equal(legacy.headers.get('location'),'index.php');
  } finally {
    server.kill();await exited;fs.rmSync(folder,{recursive:true,force:true});
  }
});
