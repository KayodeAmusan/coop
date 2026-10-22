const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../payroll_holding_setup.php','utf8');
const styles=fs.readFileSync(__dirname+'/../assets/css/cooperative-components.css','utf8');
const script=source.match(/<script>([\s\S]*?)<\/script>/)[1];
const elements=new Map();
function element(id){const attributes=new Map();return{id,value:'101',textContent:'',disabled:false,focus(){this.focused=true;},setAttribute(key,value){attributes.set(key,value);},removeAttribute(key){attributes.delete(key);},getAttribute(key){return attributes.get(key);},addEventListener(event,callback){this[event]=callback;}};}
for(const id of ['payroll-holding-form','holding-account','holding-save','holding-status','current-holding-account'])elements.set(id,element(id));
const form=elements.get('payroll-holding-form'),select=elements.get('holding-account'),save=elements.get('holding-save'),status=elements.get('holding-status'),current=elements.get('current-holding-account');
form.action='api/configure_payroll_holding.php';let valid=true;form.reportValidity=()=>valid;
select.selectedOptions=[{textContent:'1001 — Explicit existing account'}];current.textContent='Previous configured account';
const requests=[];let networkFails=false,serverFails=false,mismatched=false,pending=null,neverResponds=false;const timers=new Map();let nextTimer=0;
const context=vm.createContext({document:{getElementById:id=>elements.get(id)||null},String,Error,AbortController,setTimeout(callback,delay){const id=++nextTimer;timers.set(id,{callback,delay});return id;},clearTimeout(id){timers.delete(id);},FormData:class{constructor(form){this.account=select.value;}},fetch:async(url,options)=>{
 requests.push({url,...options});
 if(neverResponds)return new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>{const error=new Error('The operation was aborted');error.name='AbortError';reject(error);},{once:true}));
 if(pending)await pending;
 if(networkFails)throw new TypeError('Failed to fetch');
 return{ok:!serverFails,json:async()=>serverFails?{success:false,error:'<img src=x onerror=bad()> is unavailable'}:{success:true,holding_account_id:mismatched?'999':select.value}};
}});
new vm.Script(script).runInContext(context);
const submit=()=>form.submit({preventDefault(){}});
(async()=>{
 valid=false;await submit();assert.equal(requests.length,0,'Browser validation blocks empty selection');valid=true;
 let release;pending=new Promise(resolve=>{release=resolve;});const first=submit();await submit();
 assert.equal(requests.length,1,'Busy form prevents duplicate submission');assert(save.disabled);assert.equal(form.getAttribute('aria-busy'),'true');
 release();await first;pending=null;
 assert.equal(requests[0].method,'POST');assert.equal(requests[0].credentials,'same-origin');assert.equal(requests[0].body.account,'101');
 assert.equal(current.textContent,'1001 — Explicit existing account');assert.equal(status.textContent,'Payroll holding account saved.');assert.equal(status.getAttribute('role'),'status');assert(status.focused);assert(!save.disabled);assert.equal(form.getAttribute('aria-busy'),undefined);
 networkFails=true;current.textContent='Still current';await submit();assert.equal(current.textContent,'Still current');assert.equal(select.value,'101');assert.equal(status.getAttribute('role'),'alert');assert(status.textContent.includes('Unable to confirm'));assert(!save.disabled);networkFails=false;
 serverFails=true;await submit();assert(status.textContent.includes('<img src=x onerror=bad()>'));assert.equal(status.innerHTML,undefined,'Server error must use textContent, not injected markup');assert.equal(current.textContent,'Still current');serverFails=false;
 mismatched=true;await submit();assert.equal(current.textContent,'Still current');assert(status.textContent.includes('could not be verified'));assert(!save.disabled);
 assert.equal(timers.size,0,'Every completed request clears its timeout');
 mismatched=false;neverResponds=true;const requestsBeforeTimeout=requests.length;const timedOutSubmission=submit();
 assert(save.disabled);assert.equal(timers.size,1);const [timerId,timer]=[...timers.entries()][0];assert.equal(timer.delay,30000);
 await submit();assert.equal(requests.length,requestsBeforeTimeout+1,'Repeated submit cannot duplicate a pending timed request');
 timers.delete(timerId);timer.callback();await timedOutSubmission;
 assert.equal(timers.size,0);assert(!save.disabled);assert.equal(form.getAttribute('aria-busy'),undefined);assert.equal(status.getAttribute('role'),'alert');assert(status.focused);
 assert(status.textContent.includes('Reload setup to check whether the account was saved'),'Aborting transport cannot claim rollback');assert(!status.textContent.includes('No change was saved'));
 assert.equal(current.textContent,'Still current');assert.equal(select.value,'101');assert(requests.at(-1).signal.aborted);
 neverResponds=false;await submit();assert.equal(requests.length,requestsBeforeTimeout+2,'A deliberate retry starts a fresh request');assert.equal(current.textContent,'1001 — Explicit existing account');assert.equal(timers.size,0);
 assert(source.includes('name="holding_account_id" required'));assert(source.includes('for="holding-account"'));assert(source.includes('aria-describedby="holding-help holding-status"'));assert(styles.includes('outline:3px solid'));assert(styles.includes('min-height:44px'));
 console.log('PASS holding setup labelled native controls, duplicate-submit guard, POST contract, status focus, retry preservation, safe errors, saved-account verification and bounded uncertain timeout/retry');
 console.log('DOM smoke only; this is not a browser accessibility audit.');
})().catch(error=>{console.error(error);process.exitCode=1;});

