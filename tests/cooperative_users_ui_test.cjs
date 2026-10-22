const fs=require('fs'),vm=require('vm'),assert=require('assert'),{webcrypto}=require('crypto');
const source=fs.readFileSync(require('path').join(__dirname,'../assets/js/cooperative-users.js'),'utf8');
function harness(storage=new Map()){
 const elements={};for(const name of ['action','staff_user_id','username','complete_name','email','password','status'])elements[name]={value:'',disabled:false,required:false};
 const save={disabled:false},containers=['username','complete_name','email','password','status'].map(name=>({dataset:{field:name},hidden:false,querySelector:()=>elements[name]}));
 const form={elements,querySelectorAll:()=>containers,querySelector:()=>save,reset(){Object.values(elements).forEach(e=>e.value='');}};
 const nodes={'cooperative-users':{dataset:{actor:'1'}},'user-message':{textContent:''},'user-command':form,'user-save':save,'user-new':{},'users-reload':{},'users-prev':{},'users-next':{},'users-page':{},'users-body':{replaceChildren(){},append(){}}};
 const calls=[];let result='lost',confirm=true;
 const sandbox={document:{getElementById:id=>nodes[id],createElement:()=>({textContent:'',append(){}})},crypto:webcrypto,Uint8Array,console,AbortController,setTimeout,clearTimeout,sessionStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},confirm:()=>confirm,fetch:async(url,opts)=>{if(!opts?.method)return{ok:true,json:async()=>({success:true,users:[],total_records:0})};calls.push(JSON.parse(opts.body));if(result==='lost')throw Error('Lost response');return{ok:true,json:async()=>({success:true,data:{replayed:result==='replayed'}})};}};
 vm.runInNewContext(source,sandbox);
 return{elements,nodes,calls,storage,submit:()=>form.onsubmit({preventDefault(){}}),setResult:r=>result=r,setConfirm:v=>confirm=v};
}
(async()=>{
 let h=harness();Object.assign(h.elements.action,{value:'create_staff_user'});for(const[k,v]of Object.entries({staff_user_id:'10',username:'new.staff',complete_name:'New Staff',email:'new@example.test',password:'Synthetic-Password-123!',status:'Active'}))h.elements[k].value=v;
 await h.submit();assert.equal(h.calls.length,1);const original=h.calls[0];assert(![...h.storage.values()].join('').includes('Synthetic-Password'));
 await h.submit();assert.equal(h.calls.length,2);assert.equal(h.calls[1].idempotency_key,original.idempotency_key);
 h.elements.email.value='changed@example.test';await h.submit();assert.equal(h.calls.length,2);assert.match(h.nodes['user-message'].textContent,/Pending retry details changed/);
 h.elements.email.value='new@example.test';let reload=harness(h.storage);assert.equal(reload.elements.password.value,'');assert.equal(reload.elements.username.value,'new.staff');reload.elements.password.value='Synthetic-Password-123!';reload.setResult('replayed');await reload.submit();assert.equal(reload.calls[0].idempotency_key,original.idempotency_key);assert.equal(reload.storage.size,0);assert.equal(reload.elements.password.value,'');
 h.setConfirm(false);h.nodes['user-new'].onclick();assert.equal(h.storage.size,0); // reload cleared shared storage; in-memory pending still preserved.
 h.elements.email.value='new@example.test';h.setConfirm(true);h.nodes['user-new'].onclick();h.elements.action.value='set_staff_status';h.elements.staff_user_id.value='10';h.elements.status.value='Inactive';h.setResult('saved');await h.submit();assert.notEqual(h.calls.at(-1).idempotency_key,original.idempotency_key);assert.equal(h.calls.at(-1).action,'set_staff_status');
 console.log('PASS cooperative user UI preserves retry keys, blocks changed pending details, restores no passwords, confirms original results and starts explicit new intent');
})().catch(e=>{console.error(e);process.exit(1)});
