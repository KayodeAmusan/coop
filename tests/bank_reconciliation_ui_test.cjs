const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname,'../coop_bank_reconciliation.php'),'utf8');
const script = source.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g,'7');
function fixture(storage = new Map(), initialBook = '100.00') {
  const elements = new Map(), requests = [], dialogs = [], timers = new Map();
  let random=0,timerId=0,reloads=0,mode='network',confirmed=true,storageFails=false;
  function el(id) { if(!elements.has(id))elements.set(id,{value:'',textContent:'',className:'',disabled:false,handlers:{},addEventListener(name,fn){this.handlers[name]=fn;},async fire(name){return this.handlers[name]?.call(this,{preventDefault(){}});}});return elements.get(id); }
  const mapping={bank_account_id:'bankAccountSelect',periodid:'periodSelect',reconciliation_date:'date',book_balance:'bookBalance',bank_statement_balance:'bankBalance',outstanding_deposits:'outstandingDeposits',outstanding_withdrawals:'outstandingWithdrawals',bank_charges:'bankCharges',bank_interest:'bankInterest',notes:'notes',idempotency_key:'recon-idempotency-key'};
  const initial={bank_account_id:'101',periodid:'1',reconciliation_date:'2026-10-21',book_balance:initialBook,bank_statement_balance:'99.90',outstanding_deposits:'0.00',outstanding_withdrawals:'0.00',bank_charges:'0.30',bank_interest:'0.20',notes:'Synthetic',idempotency_key:'initial-server-key'};
  for(const [name,id]of Object.entries(mapping))el(id).value=initial[name];
  const form=el('reconForm');form.elements={namedItem:name=>el(mapping[name])};form.reset=()=>{for(const[name,id]of Object.entries(mapping))el(id).value=['outstanding_deposits','outstanding_withdrawals','bank_charges','bank_interest'].includes(name)?'0.00':name==='idempotency_key'?'initial-server-key':'';};
  const context=vm.createContext({console,Uint8Array,BigInt,AbortController,
    document:{getElementById:el,querySelectorAll:()=>['outstandingDeposits','outstandingWithdrawals','bankCharges','bankInterest'].map(el)},
    FormData:class {constructor(input){this.values=new Map();if(input)for(const[name,id]of Object.entries(mapping))this.values.set(name,el(id).value);}get(name){return this.values.get(name)??null;}append(name,value){this.values.set(name,value);}},
    crypto:{getRandomValues(bytes){bytes.fill(++random);return bytes;}},
    sessionStorage:{getItem:key=>storage.get(key)??null,setItem(key,value){if(storageFails)throw Error('Quota unavailable');storage.set(key,value);},removeItem:key=>storage.delete(key)},
    setTimeout(callback,delay){const id=++timerId;timers.set(id,{callback,delay});return id;},clearTimeout:id=>timers.delete(id),
    Swal:{async fire(options){dialogs.push(options);return {isConfirmed:confirmed};}},location:{reload(){reloads++;}},
    fetch:async(url,options={})=>{if(url.startsWith('api/get_book_balance')){requests.push({read:true,url});return {ok:true,json:async()=>({success:true,balance:0,balance_decimal:'1234567890123.45'})};}
      requests.push({read:false,payload:Object.fromEntries(options.body.values)});
      if(mode==='network')throw Error('Synthetic lost response');
      if(mode==='hang')return new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>{const error=Error('Timed out');error.name='AbortError';reject(error);}));
      return {ok:true,status:200,json:async()=>({success:true,replayed:true,reconciliation_id:9,is_balanced:true,variance:'0.00'})};
    }});
  vm.runInContext(script,context);
  return {el,form,requests,dialogs,timers,storage,run:code=>vm.runInContext(code,context),setMode:value=>mode=value,setConfirmed:value=>confirmed=value,setStorageFailure:value=>storageFails=value,reloads:()=>reloads,posts:()=>requests.filter(r=>!r.read)};
}
(async()=>{
  const f=fixture();await f.form.fire('submit');assert.equal(f.posts().length,1);const first=f.posts()[0].payload;
  assert.equal(first.idempotency_key,'initial-server-key');assert.equal(first.book_balance,'100.00');assert(!('reconciled_by'in first));assert.equal(f.el('recon-idempotency-key').value,'initial-server-key');assert.equal(f.storage.size,1);
  await f.form.fire('submit');assert.equal(f.posts()[1].payload.idempotency_key,first.idempotency_key,'Uncertain retry retains key');assert.deepEqual(f.posts()[1].payload,first,'Uncertain retry retains exact original payload');
  f.el('bankCharges').value='0.31';await f.form.fire('submit');assert.equal(f.posts().length,2,'Changed pending intent must not post');assert.match(f.el('recon-submission-status').textContent,/different details/);f.el('bankCharges').value='0.30';
  const restored=fixture(f.storage,'999.99');assert.equal(restored.el('bookBalance').value,'100.00','Reload restores original book balance for idempotent replay');assert.equal(restored.el('recon-idempotency-key').value,first.idempotency_key);await restored.run('fetchBookBalance()');assert.equal(restored.requests.length,0,'Pending payload is not refreshed from mutable current book');
  restored.setMode('success');await restored.form.fire('submit');assert.equal(restored.posts()[0].payload.idempotency_key,first.idempotency_key);assert.equal(restored.storage.size,0);assert.notEqual(restored.el('recon-idempotency-key').value,first.idempotency_key);assert(restored.el('recon-submit').disabled,'Confirmed operation stays disabled until new intent or reload');await restored.form.fire('submit');assert.equal(restored.posts().length,1);assert.equal(restored.reloads(),1);
  await restored.run('resetForm()');assert.equal(restored.el('recon-submit').disabled,false);assert.match(restored.el('recon-idempotency-key').value,/^[a-f0-9]{48}$/);
  const pending=fixture();await pending.form.fire('submit');pending.setConfirmed(false);await pending.run('resetForm()');assert.equal(pending.el('recon-idempotency-key').value,'initial-server-key');assert.equal(pending.storage.size,1);pending.setConfirmed(true);await pending.run('resetForm()');assert.equal(pending.storage.size,0);assert.notEqual(pending.el('recon-idempotency-key').value,'initial-server-key');
  const timeout=fixture();timeout.setMode('hang');const saving=timeout.form.fire('submit');await Promise.resolve();await Promise.resolve();assert.equal(timeout.timers.size,1);const timer=[...timeout.timers.values()][0];assert.equal(timer.delay,30000);timer.callback();await saving;assert.equal(timeout.el('recon-idempotency-key').value,'initial-server-key');assert.equal(timeout.storage.size,1);assert.match(timeout.el('recon-submission-status').textContent,/timed out/);assert.equal(timeout.timers.size,0);
  const noStorage=fixture();noStorage.setStorageFailure(true);await noStorage.form.fire('submit');assert.equal(noStorage.posts().length,0);assert(noStorage.el('recon-submit').disabled,'No recovery persistence means no financial request');
  const money=fixture();money.el('bankBalance').value='100.01';money.el('bankCharges').value='0.00';money.el('bankInterest').value='0.00';money.run('calculateReconciliation()');assert.equal(money.el('variance').textContent,'₦0.01');assert.match(money.el('varianceStatus').textContent,/variance/);await money.run('fetchBookBalance()');assert.equal(money.el('bookBalance').value,'1234567890123.45','Precise server decimal is never round-tripped through binary float');
  const cryptoFailure=fixture();cryptoFailure.run('crypto=undefined');await cryptoFailure.run('resetForm()');assert(cryptoFailure.el('recon-submit').disabled);assert.match(cryptoFailure.el('recon-submission-status').textContent,/Secure submission references/);
  assert(source.includes('bin2hex(random_bytes(24))'));assert(source.includes('name="idempotency_key"'));assert(!script.includes("formData.append('reconciled_by'"));
  console.log('PASS bank reconciliation retry identity, reload recovery, timeout, exact DTO and new-intent UI checks');
})().catch(error=>{console.error(error);process.exitCode=1;});
