const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const source=fs.readFileSync(__dirname+'/../member_guarantor_evidence.php','utf8')+fs.readFileSync(__dirname+'/../includes/guarantor_evidence_view.php','utf8');
const script=fs.readFileSync(__dirname+'/../assets/js/guarantor_evidence.js','utf8');
const flush=()=>new Promise(setImmediate),sharedStorage=new Map();
function boot(){
 const nodes=new Map(),requests=[],timers=new Map(),downloads=[],revoked=[];let timerId=0,nonce=0;
 const state={loginOk:true,failUpload:true,expired:false,storageUnavailable:false,member:'COOP-000001',neverUpload:false,neverLogin:false};
 function element(tag='div'){
  let id='';const listeners=new Map(),attributes=new Map();
  return {tagName:tag.toUpperCase(),value:'',textContent:'',disabled:false,hidden:false,children:[],files:[],dataset:{},
   get id(){return id;},set id(v){id=v;nodes.set(v,this);},setAttribute(k,v){attributes.set(k,v);},getAttribute(k){return attributes.get(k);},removeAttribute(k){attributes.delete(k);},focus(){this.focused=true;},
   appendChild(child){this.children.push(child);return child;},replaceChildren(...children){this.children=children;},querySelectorAll(){return[];},
   addEventListener(k,fn){listeners.set(k,fn);},fire(k){return listeners.get(k)?.({preventDefault(){}});},reportValidity(){return true;},
   reset(){get('evidence-file').files=[];get('evidence-kind').value='';},click(){downloads.push(this.href);},remove(){this.removed=true;}
  };
 }
 for(const match of source.matchAll(/<([a-z]+)[^>]*?\sid="([^"]+)"/g)){const n=element(match[1]);n.id=match[2];}
 const get=id=>nodes.get(id),root=get('guarantor-evidence-review');root.dataset.member='true';root.dataset.reviewerId='';root.querySelectorAll=()=>[...nodes.values()].filter(n=>!n.id.startsWith('member-') && ['INPUT','SELECT','BUTTON','TEXTAREA'].includes(n.tagName));
 class Form {constructor(){this.fields=new Map([['evidence_kind',get('evidence-kind').value],['evidence',get('evidence-file').files[0]]]);}append(k,v){this.fields.set(k,String(v));}get(k){return this.fields.get(k);}}
 const doc={public_id:'a'.repeat(32),loan_request_id:1,guarantor_request_id:11,guarantor_coop_id:'G',evidence_kind:'approval',version_number:1,terms_hash:'terms',content_sha256:'hash',byte_size:12,mime_type:'application/pdf',scan_status:'clean',uploaded_by_type:'member',uploaded_by_id:'G',uploaded_at:'test',review_status:'pending',current_terms:true,is_latest:true};
 class TestURL extends URL {static createObjectURL(){return'blob:private-test';}static revokeObjectURL(url){revoked.push(url);}}
 const context=vm.createContext({document:{getElementById:get,createElement:element,body:element('body')},FormData:Form,URL:TestURL,URLSearchParams,AbortController,Uint8Array,TextEncoder,Error,String,Number,Array,JSON,Map,confirm:()=>true,
  crypto:{subtle:{async digest(name,bytes){return crypto.createHash('sha256').update(Buffer.from(bytes)).digest();}},getRandomValues(bytes){bytes.fill(++nonce);return bytes;}},
  sessionStorage:{getItem(k){if(state.storageUnavailable)throw new Error();return sharedStorage.get(k)||null;},setItem(k,v){if(state.storageUnavailable)throw new Error();sharedStorage.set(k,v);},removeItem(k){if(state.storageUnavailable)throw new Error();sharedStorage.delete(k);}},
  setTimeout(fn,delay){const id=++timerId;timers.set(id,{fn,delay});return id;},clearTimeout(id){timers.delete(id);},
  fetch:async(url,options)=>{
   requests.push({url,options});let data;
   const never=()=>new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>{const e=new Error();e.name='AbortError';reject(e);},{once:true}));
   if(url.endsWith('auth/login.php')){if(state.neverLogin)return never();return{ok:state.loginOk,status:state.loginOk?200:401,json:async()=>({success:state.loginOk,token:'memory-only-token',user:{CoopID:state.member}})};}
   assert.equal(options.headers.Authorization,'Bearer memory-only-token');assert.equal(options.credentials,'omit');
   if(state.expired)return{ok:false,status:401,json:async()=>({success:false})};
   if(options.body instanceof Form){if(state.neverUpload)return never();if(state.failUpload)throw new TypeError('network');data={...doc,scan_status:'quarantined'};}
   else if(url.includes('/loans/request.php'))data=[{id:1,status:'submitted'}];
   else if(url.includes('guarantor-request.php'))data=[{loan_request_id:2,loan_status:'submitted'}];
   else {const query=new URL('http://test/'+url).searchParams;
    if(query.get('action')==='download')return{ok:true,status:200,blob:async()=>({type:'application/pdf'})};
    data=query.get('action')==='requests'?{loan_request_id:1,requester_coop_id:state.member,loan_status:'submitted',terms_hash:'terms',requests:[{guarantor_request_id:11,guarantor_coop_id:'G',status:'approved'}]}:{items:[doc],next_cursor:null};}
   return{ok:true,status:200,json:async()=>({success:true,data})};
  }});
 new vm.Script(script).runInContext(context);
 return {get,state,requests,timers,downloads,revoked,run:code=>vm.runInContext(code,context),async login(){get('member-username').value='member@example.test';get('member-password').value='synthetic';get('member-login-form').fire('submit');await flush();},async select(){get('member-loan-choice').value='1';get('member-loan-choice').fire('change');get('evidence-association-form').fire('submit');await flush();get('evidence-invitation').value='11';get('evidence-invitation').fire('change');await flush();},file(content='same bytes'){get('evidence-kind').value='approval';get('evidence-file').files=[{name:'not-stored.pdf',size:12,type:'application/pdf',arrayBuffer:async()=>new TextEncoder().encode(content).buffer}];},Form};
}
const descendants=node=>[node,...node.children.flatMap(descendants)];
(async()=>{
 let ui=boot();assert.equal(ui.requests.length,0);ui.state.loginOk=false;await ui.login();assert.equal(ui.get('member-password').value,'');assert(ui.get('member-login-status').textContent.includes('failed'));assert.equal(sharedStorage.size,0);
 ui.state.loginOk=true;await ui.login();assert.equal(ui.requests.find(r=>r.url.endsWith('auth/login.php')).options.credentials,'omit');assert(ui.get('member-loan-choice').children.some(n=>n.value==='1'));assert(ui.get('member-loan-choice').children.some(n=>n.value==='2'));assert.equal(ui.get('member-loan-choice').value,'');await ui.select();
 assert(!descendants(ui.get('evidence-history')).some(n=>n.tagName==='FORM'),'Member never receives staff review controls');assert(!descendants(ui.get('evidence-history')).some(n=>n.textContent==='Retry malware scan'));
 const download=descendants(ui.get('evidence-history')).find(n=>n.tagName==='A');download.fire('click');await flush();assert.deepEqual(ui.downloads,['blob:private-test']);assert.deepEqual(ui.revoked,['blob:private-test']);
 ui.file();ui.get('evidence-upload-form').fire('submit');await flush();const first=ui.requests.findLast(r=>r.options.body instanceof ui.Form).options.body.get('upload_key');assert.equal(sharedStorage.size,1);const stored=[...sharedStorage.values()][0];assert(!stored.includes('token'));assert(!stored.includes('COOP'));assert(!stored.includes('not-stored'));assert(!stored.includes('same bytes'));assert(ui.get('evidence-upload-status').textContent.includes('unconfirmed'));
 ui.get('member-signout').fire('click');assert.equal(ui.run('evidenceToken'),null);assert.equal(sharedStorage.size,1);assert.equal(ui.get('evidence-file').files.length,0);
 ui=boot();await ui.login();await ui.select();ui.file();ui.get('evidence-upload-form').fire('submit');await flush();assert.equal(ui.requests.findLast(r=>r.options.body instanceof ui.Form).options.body.get('upload_key'),first,'Reload + login + same content restores original key');
 ui.file('changed bytes');let count=ui.requests.length;ui.get('evidence-upload-form').fire('submit');await flush();assert.equal(ui.requests.length,count,'Changed content cannot silently discard uncertain intent');ui.get('evidence-new-upload').fire('click');assert.equal(sharedStorage.size,0);
 ui.file();ui.state.storageUnavailable=true;count=ui.requests.length;ui.get('evidence-upload-form').fire('submit');await flush();assert.equal(ui.requests.length,count);assert(ui.get('evidence-upload-status').textContent.includes('storage'));ui.state.storageUnavailable=false;
 ui.state.neverUpload=true;ui.get('evidence-upload-form').fire('submit');await flush();count=ui.requests.length;ui.get('evidence-upload-form').fire('submit');assert.equal(ui.requests.length,count);const[id,timer]=[...ui.timers.entries()][0];assert.equal(timer.delay,30000);ui.timers.delete(id);timer.fn();await flush();assert(ui.get('evidence-upload-status').textContent.includes('before submitting again'));assert.equal(sharedStorage.size,1);
 ui.state.neverUpload=false;ui.state.failUpload=false;ui.get('evidence-upload-form').fire('submit');await flush();assert.equal(sharedStorage.size,0);assert(ui.get('evidence-upload-status').textContent.includes('quarantined'));
 ui.state.expired=true;ui.get('evidence-reload').fire('click');await flush();assert.equal(ui.run('evidenceToken'),null);assert.equal(ui.get('member-workspace').hidden,true);assert.equal(ui.get('member-login-section').hidden,false);
 ui=boot();ui.state.neverLogin=true;ui.get('member-username').value='synthetic';ui.get('member-password').value='synthetic';ui.get('member-login-form').fire('submit');await flush();const loginTimer=[...ui.timers.values()][0];loginTimer.fn();await flush();assert(ui.get('member-login-status').textContent.includes('timed out'));assert.equal(ui.get('member-login-submit').disabled,false);
 assert(!script.includes('localStorage'));assert(!script.includes('innerHTML'));console.log('PASS member login, own/invited loan discovery, role isolation, Bearer blob download/revoke, refresh-stable content key, changed-payload conflict, storage fail-closed, timeout/busy and expired-login cleanup. DOM contracts only; no rendered browser claim.');
})().catch(error=>{console.error(error);process.exitCode=1;});
