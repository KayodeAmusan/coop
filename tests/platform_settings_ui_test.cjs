const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const script=fs.readFileSync(__dirname+'/../assets/js/platform_settings.js','utf8'),storage=new Map(),flush=()=>new Promise(setImmediate);
const base={branding:{configured:true,revision:0,cooperative_name:'Synthetic cooperative',primary_color:'#2448a0',accent_color:'#7041a6',logo_asset_id:null,favicon_asset_id:null,logo_url:null,favicon_url:null},integrations:{smtp:{revision:0,enabled:false,config:{host:'smtp.example.test',port:587,encryption:'starttls',username:'fixture',from_email:'sender@example.test',from_name:'Fixture'},secrets:{password:{configured:true}}},paystack:{revision:0,enabled:false,config:{mode:'test',public_key:'pk_test_synthetic'},secrets:{secret_key:{configured:false}}},sms:{revision:0,enabled:false,config:{adapter:'custom_http',endpoint_url:'https://sms.example.test/send',http_method:'POST',auth_style:'bearer',auth_field:'',sender_id:'COOP',recipient_field:'to',message_field:'message',sender_field:''},secrets:{api_key:{configured:false}}}},sms_providers:[]};
let server=structuredClone(base);
function boot(){
 const nodes=new Map(),requests=[],timers=new Map(),redirects=[];let nextTimer=0,nonce=0;
 const state={failSave:true,neverSave:false,storageBlocked:false,networkTested:false};
 function element(id,tag='input'){const attrs=new Map(),listeners=new Map();return{id,tagName:tag.toUpperCase(),dataset:{},style:{},value:'',textContent:'',disabled:false,hidden:false,checked:false,files:[],listeners,reportValidity:()=>true,addEventListener(k,fn){listeners.set(k,fn);},fire(k){return listeners.get(k)?.({preventDefault(){},currentTarget:this});},setAttribute(k,v){attrs.set(k,v);},removeAttribute(k){attrs.delete(k);},getAttribute(k){return attrs.get(k);},focus(){this.focused=true;}};}
 function get(id){if(!nodes.has(id))nodes.set(id,element(id));return nodes.get(id);}
 const root=get('platform-settings');root.dataset.actorId='19';
 for(const id of ['platform-reload','platform-signout'])get(id).tagName='BUTTON';
 const groups={'[data-settings-fields]':[],'[data-clear-asset]':[],'[data-new-command]':[],'[data-test-provider]':[]};
 for(const kind of ['logo','favicon']){groups['[data-settings-fields]'].push(element('fieldset-'+kind,'fieldset'));const b=element('clear-'+kind,'button');b.dataset.clearAsset=kind;groups['[data-clear-asset]'].push(b);}
 for(const scope of ['branding','asset_logo','asset_favicon','smtp','paystack','sms']){const b=element('new-'+scope,'button');b.dataset.newCommand=scope;groups['[data-new-command]'].push(b);nodes.set(b.id,b);}
 for(const provider of ['smtp','paystack','sms']){groups['[data-settings-fields]'].push(element('fieldset-'+provider,'fieldset'));for(const field of Object.keys(base.integrations[provider].config))get(provider+'-'+field);get(provider+'-secret-action').value='retain';}
 for(const provider of ['smtp','paystack']){const b=element('test-'+provider,'button');b.dataset.testProvider=provider;groups['[data-test-provider]'].push(b);nodes.set(b.id,b);}
 root.querySelectorAll=selector=>groups[selector]||[...nodes.values()].filter(n=>['INPUT','BUTTON','SELECT','TEXTAREA'].includes(n.tagName));
 class Form{constructor(){this.fields=new Map();}append(k,v){this.fields.set(k,v);}get(k){return this.fields.get(k);}}
 const context=vm.createContext({document:{getElementById:get},FormData:Form,AbortController,TextEncoder,Uint8Array,JSON,Number,Array,Object,Error,TypeError,SyntaxError,confirm:()=>true,window:{location:{assign(url){redirects.push(url);}}},
 crypto:{subtle:{async digest(name,bytes){return crypto.createHash('sha256').update(Buffer.from(bytes)).digest();}},getRandomValues(bytes){bytes.fill(++nonce);return bytes;}},
 sessionStorage:{getItem(k){if(state.storageBlocked)throw new Error();return storage.get(k)||null;},setItem(k,v){if(state.storageBlocked)throw new Error();storage.set(k,v);},removeItem(k){if(state.storageBlocked)throw new Error();storage.delete(k);}},
 setTimeout(fn,delay){const id=++nextTimer;timers.set(id,{fn,delay});return id;},clearTimeout(id){timers.delete(id);},
 fetch:async(url,options)=>{const payload=options.body instanceof Form?options.body:options.body?JSON.parse(options.body):null;requests.push({url,options,payload});let data=structuredClone(server);
  if(payload instanceof Form)data={asset_id:'a'.repeat(32),url:'api/branding_asset.php?id='+'a'.repeat(32)};
  else if(payload?.action==='test_connection')data={network_tested:state.networkTested,connected:state.networkTested};
  else if(payload?.action?.startsWith('save_')){
   if(state.neverSave)return new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>{const e=new Error();e.name='AbortError';reject(e);},{once:true}));
   if(payload.action==='save_branding')server.branding={...server.branding,...payload.branding,revision:1};
   if(state.failSave)throw new TypeError('uncertain network');
   data={revision:1};
  }
  return{ok:true,json:async()=>({success:true,data})};
 }});
 new vm.Script(script).runInContext(context);
 return{get,state,requests,timers,redirects,Form,async submit(id){get(id).fire('submit');await flush();}};
}
(async()=>{
 let ui=boot();await flush();assert.equal(ui.get('brand-name').value,'Synthetic cooperative');assert.equal(ui.get('platform-branding-fields').disabled,false);assert(ui.get('smtp-secret-state').textContent.includes('hidden'));
 ui.get('brand-name').value='Updated cooperative';await ui.submit('platform-branding-form');let first=ui.requests.findLast(r=>r.payload?.action==='save_branding').payload;assert.equal(first.expected_revision,0);assert.match(first.command_key,/^[a-f0-9]{48}$/);assert.equal(storage.size,1);
 ui=boot();await flush();assert.equal(ui.get('brand-name').value,'Updated cooperative');await ui.submit('platform-branding-form');let retry=ui.requests.findLast(r=>r.payload?.action==='save_branding').payload;assert.equal(retry.command_key,first.command_key);assert.equal(retry.expected_revision,0,'Lost acknowledgement replay retains original revision after reload');
 ui.get('brand-name').value='Different';let count=ui.requests.length;await ui.submit('platform-branding-form');assert.equal(ui.requests.length,count);assert(ui.get('branding-status').textContent.includes('different values'));ui.get('new-branding').fire('click');assert.equal(storage.size,0);
 ui.state.storageBlocked=true;count=ui.requests.length;await ui.submit('platform-branding-form');assert.equal(ui.requests.length,count);ui.state.storageBlocked=false;
 ui.get('smtp-secret-action').value='replace';ui.get('smtp-secret-action').fire('change');assert.equal(ui.get('smtp-secret-value').disabled,false);ui.get('smtp-secret-value').value='synthetic-secret';await ui.submit('platform-integration-smtp');assert(!JSON.stringify([...storage]).includes('synthetic-secret'),'Only opaque fingerprints are stored');const smtp=ui.requests.findLast(r=>r.payload?.provider==='smtp').payload;assert.equal(smtp.secrets.password.action,'replace');assert.equal(smtp.secrets.password.value,'synthetic-secret');
 ui.get('smtp-secret-action').value='clear';count=ui.requests.length;await ui.submit('platform-integration-smtp');assert.equal(ui.requests.length,count,'Changed secret operation cannot reuse pending receipt');ui.get('new-smtp').fire('click');ui.state.failSave=false;await ui.submit('platform-integration-smtp');assert.equal(ui.get('smtp-secret-value').value,'');assert.equal(ui.get('smtp-secret-action').value,'retain');
 ui.get('test-smtp').fire('click');await flush();assert(ui.get('smtp-status').textContent.includes('not tested'));ui.state.networkTested=true;ui.get('test-paystack').fire('click');await flush();assert(ui.get('paystack-status').textContent.includes('No message or payment'));
 ui.state.neverSave=true;ui.get('brand-name').value='Stalled';ui.get('platform-branding-form').fire('submit');await flush();count=ui.requests.length;ui.get('platform-branding-form').fire('submit');assert.equal(ui.requests.length,count);const[id,timer]=[...ui.timers.entries()][0];assert.equal(timer.delay,30000);ui.timers.delete(id);timer.fn();await flush();assert(ui.get('branding-status').textContent.includes('timed out'));assert.equal(ui.get('platform-settings').getAttribute('aria-busy'),undefined);
 ui.get('asset-logo').files=[{size:12,arrayBuffer:async()=>new TextEncoder().encode('synthetic image').buffer}];await ui.submit('platform-asset-logo');assert(ui.get('brand-logo-state').textContent.includes('a'.repeat(32)));assert(ui.get('asset-logo-status').textContent.includes('Save branding'));
 ui.get('platform-signout').fire('click');await flush();assert.deepEqual(ui.redirects,['platform_login.php']);assert(!script.includes('localStorage'));assert(!script.includes('innerHTML'));console.log('PASS platform settings safe rendering, stable key/revision across refresh, changed payload conflict, storage fail-closed, secret actions without persisted plaintext, live-test truthfulness, timeout/busy recovery, asset upload and logout. Synthetic DOM/API only.');
})().catch(e=>{console.error(e);process.exitCode=1;});

