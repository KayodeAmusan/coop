const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../loan_product_setup.php','utf8');
const script=source.match(/<script>([\s\S]*?)<\/script>/)[1];
function element(tag='div') {const attributes=new Map(),listeners=new Map();return {tagName:tag.toUpperCase(),value:'',textContent:'',disabled:false,hidden:false,children:[],focused:false,
 setAttribute(k,v){attributes.set(k,v);},removeAttribute(k){attributes.delete(k);},getAttribute(k){return attributes.get(k);},
 addEventListener(k,fn){listeners.set(k,fn);},async fire(k){return listeners.get(k)?.({preventDefault(){}});},focus(){this.focused=true;},reportValidity(){return valid;},
 appendChild(child){this.children.push(child);return child;},replaceChildren(){this.children=[];this.value='';},querySelectorAll(){return []}
};}
let valid=true;const elements=new Map([...source.matchAll(/id="([^"]+)"/g)].map(match=>[match[1],element()]));
const byId=id=>elements.get(id);byId('calculation-rate-fields').disabled=true;byId('calculation-manual-fields').disabled=true;
byId('loan-product-setup').querySelectorAll=()=>[...elements.values()].filter(e=>e!==byId('loan-product-setup'));
const requests=[],timers=new Map();let timerId=0,random=0,failPost=false,neverResponds=false,serverError=false;
const calculation={principal:'123.45',total_interest:'6.78',total_repayable:'130.23',installment_count:1,installments:[{number:1,opening_principal:'123.45',principal:'123.45',interest:'6.78',repayment:'130.23',closing_principal:'0.00'}]};
const context=vm.createContext({document:{getElementById:byId,createElement:element},URLSearchParams,AbortController,Uint8Array,Error,String,Number,Array,JSON,
 crypto:{getRandomValues(bytes){bytes.fill(++random);return bytes;}},setTimeout(callback,delay){const id=++timerId;timers.set(id,{callback,delay});return id;},clearTimeout(id){timers.delete(id);},
 fetch:async(url,options)=>{
  const payload=options.body?JSON.parse(options.body):null;requests.push({url,options,payload});
  if(payload&&neverResponds)return new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>{const error=new Error('Aborted');error.name='AbortError';reject(error);},{once:true}));
  if(payload&&failPost)throw new TypeError('Failed to fetch');
  if(payload&&serverError)return{ok:false,json:async()=>({success:false,message:'<img src=x onerror=bad()> rejected'})};
  let data;
  if(!payload){const params=new URL('http://test/'+url).searchParams;data=params.get('action')==='list'?{items:[{id:1,product_code:'TEST',name:'<img src=x onerror=bad()>',status:'draft'}],page:Number(params.get('page')),limit:20,total:21}:{items:[{id:10,version_number:3,created_at:'Synthetic time',terms_text:'<img src=x onerror=bad()>',content_hash:'abc',configuration:{calculation_inputs:{method:'interest_free'}}}],page:1,limit:20,total:1};}
  else if(payload.action==='preview')data=calculation;
  else if(payload.action==='create_draft')data={id:1,status:'draft',replayed:false};
  else data={id:11,product_id:1,version_number:4,status:'draft',replayed:false};
  return{ok:true,json:async()=>({success:true,draft_only:true,applications_enabled:false,data})};
 }});
new vm.Script(script).runInContext(context);
const run=code=>vm.runInContext(code,context),flush=()=>new Promise(setImmediate);
const set=(id,value)=>{byId(id).value=value;};
function common(method='interest_free') {set('calculation-method',method);set('calculation-principal','900.00');set('calculation-count','3');set('calculation-rounding','half_up');set('calculation-residual','adjust_final_installment');set('calculation-fees','none');run('draftMethodFields()');}
function postRequests(){return requests.filter(r=>r.payload);}
(async()=>{
 await flush();assert.equal(byId('draft-product').value,'','Loaded draft is never auto-selected');assert.equal(byId('products-next').disabled,false);assert.equal(timers.size,0);
 for(const method of ['interest_free','flat','reducing_equal_installments','reducing_equal_principal','interest_only_balloon','manual_schedule']){
  common(method);set('calculation-rate-basis','per_installment');set('calculation-rate','0.01');
  if(method==='manual_schedule'){set('calculation-count','2');set('calculation-manual-interest','3.00');set('calculation-manual-schedule','300.00,1.00\n600.00,2.00');}
  await run('draftCalculate(false)');const input=postRequests().at(-1).payload.calculation_inputs;
  assert.equal(input.method,method);assert.equal(typeof input.principal,'string');assert.equal(input.principal,'900.00');assert.equal(input.fees.handling,'none');
  if(['interest_free','manual_schedule'].includes(method)){assert(!('rate_basis'in input));assert(!('rate_per_installment'in input));}
  else{assert.equal(input.rate_basis,'per_installment');assert.equal(input.rate_per_installment,'0.01');}
  if(method==='manual_schedule'){assert.deepEqual(input.schedule,[{principal:'300.00',interest:'1.00'},{principal:'600.00',interest:'2.00'}]);assert.equal(input.reviewed_total_interest,'3.00');}
  assert(byId('calculation-totals').textContent.includes('₦123.45'),'UI uses returned server amount, never local arithmetic');assert.equal(byId('calculation-installments').children[0].children[4].textContent,'130.23');
 }
 common();let before=postRequests().length;set('calculation-fees','unsupported');await run('draftCalculate(false)');assert.equal(postRequests().length,before);assert(byId('draft-calculation-status').textContent.includes('unsupported'));
 common('flat');set('calculation-rate-basis','unsupported');await run('draftCalculate(false)');assert.equal(postRequests().length,before);
 valid=false;await run('draftCalculate(false)');assert.equal(postRequests().length,before);valid=true;
 set('draft-code','TEST');set('draft-name','Synthetic candidate');set('draft-description','Unapproved');failPost=true;
 await byId('draft-create-form').fire('submit');const createKey=postRequests().at(-1).payload.idempotency_key;assert.match(createKey,/^[a-f0-9]{48}$/);
 await byId('draft-create-form').fire('submit');assert.equal(postRequests().at(-1).payload.idempotency_key,createKey,'Uncertain create retry retains command identity');
 before=postRequests().length;set('draft-name','Changed after uncertainty');await byId('draft-create-form').fire('submit');assert.equal(postRequests().length,before);assert(byId('draft-create-status').textContent.includes('unconfirmed'));
 await byId('draft-new-submission').fire('click');await byId('draft-create-form').fire('submit');assert.notEqual(postRequests().at(-1).payload.idempotency_key,createKey,'Explicit new submission rotates identity');
 failPost=false;await byId('draft-create-form').fire('submit');assert(byId('draft-create-status').textContent.includes('saved'));assert.equal(byId('draft-product').value,'1');
 common();set('draft-terms','Unapproved candidate terms');set('draft-product','1');neverResponds=true;
 const timed=run('draftCalculate(true)');before=postRequests().length;await run('draftCalculate(true)');assert.equal(postRequests().length,before,'Busy version cannot duplicate pending request');
 const versionKey=postRequests().at(-1).payload.idempotency_key;assert.equal(timers.size,1);const [id,timer]=[...timers.entries()][0];assert.equal(timer.delay,30000);timers.delete(id);timer.callback();await timed;
 assert.equal(timers.size,0);assert.equal(byId('loan-product-setup').getAttribute('aria-busy'),undefined);assert(byId('draft-calculation-status').textContent.includes('whether a save completed'));assert(byId('draft-calculation-status').focused);
 neverResponds=false;await run('draftCalculate(true)');assert.equal(postRequests().at(-1).payload.idempotency_key,versionKey);assert(byId('draft-calculation-status').textContent.includes('Immutable draft version 4 saved'));
 serverError=true;await run('draftCalculate(false)');assert(byId('draft-calculation-status').textContent.includes('<img src=x onerror=bad()>'));assert.equal(byId('draft-calculation-status').innerHTML,undefined);serverError=false;
 assert(source.includes('1% is entered as the fraction 0.01, not 1'));assert(source.includes('does not prove an authenticated staff review'));assert(source.includes('no due dates'));assert(!script.includes('parseFloat('));assert(!script.includes('innerHTML'));
 console.log('PASS six-method server payloads, explicit unsupported fees/rates, immutable version requests, no local money formulas, retry identities, timeout/busy recovery and safe draft-only rendering');
 console.log('DOM contract tests only; no rendered browser or actual API integration claim.');
})().catch(error=>{console.error(error);process.exitCode=1;});
