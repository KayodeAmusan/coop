const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../includes/guarantor_evidence_view.php','utf8'),script=fs.readFileSync(__dirname+'/../assets/js/guarantor_evidence.js','utf8');
const elements=new Map();let valid=true;
function remove(element){for(const child of element.children)remove(child);if(element.id)elements.delete(element.id);}
function element(tag='div'){
 const attributes=new Map(),listeners=new Map();let id='';
 return{tagName:tag.toUpperCase(),value:'',textContent:'',disabled:false,hidden:false,children:[],files:[],dataset:{},
 get id(){return id;},set id(value){id=value;if(value)elements.set(value,this);},
 setAttribute(k,v){attributes.set(k,v);},getAttribute(k){return attributes.get(k);},removeAttribute(k){attributes.delete(k);},focus(){this.focused=true;},
 addEventListener(k,fn){listeners.set(k,fn);},async fire(k){return listeners.get(k)?.({preventDefault(){}});},reportValidity(){return valid;},
 appendChild(child){this.children.push(child);return child;},replaceChildren(...children){this.children.forEach(remove);this.children=[];children.forEach(child=>this.appendChild(child));},
 reset(){byId('evidence-file').files=[];byId('evidence-kind').value='';},querySelectorAll(){return[];}
 };
}
for(const match of source.matchAll(/<([a-z]+)[^>]*?\sid="([^"]+)"/g)){const node=element(match[1]);node.id=match[2];}
const byId=id=>elements.get(id)||null;byId('guarantor-evidence-review').dataset.reviewerId='7';
byId('guarantor-evidence-review').querySelectorAll=()=>[...elements.values()].filter(e=>['INPUT','SELECT','TEXTAREA','BUTTON'].includes(e.tagName));
const requests=[],timers=new Map();let nextTimer=0,random=0,failUpload=false,neverUpload=false,mismatch=false;
const documentBase={public_id:'a'.repeat(32),loan_request_id:1,guarantor_request_id:11,guarantor_coop_id:'G',evidence_kind:'approval',version_number:1,terms_hash:'terms',content_sha256:'hash',byte_size:12,mime_type:'application/pdf',scan_status:'clean',uploaded_by_type:'member',uploaded_by_id:'G',uploaded_at:'Synthetic upload time',review_status:'pending',reviewed_by_type:null,reviewed_by_id:null,reviewed_at:null,review_notes:null,current_terms:true,is_latest:true};
let documents=[{...documentBase}],cursor='9';
class UploadFormData{constructor(){this.fields=new Map([['evidence_kind',byId('evidence-kind').value],['evidence',byId('evidence-file').files[0]]]);}append(k,v){this.fields.set(k,String(v));}get(k){return this.fields.get(k);}}
const context=vm.createContext({document:{getElementById:byId,createElement:element},URLSearchParams,AbortController,Uint8Array,Error,String,Number,Array,JSON,Map,FormData:UploadFormData,
 TextEncoder, sessionStorage:{values:new Map(),getItem(k){return this.values.get(k)||null;},setItem(k,v){this.values.set(k,v);},removeItem(k){this.values.delete(k);}},confirm:()=>true,
 crypto:{subtle:{async digest(name,bytes){return require('node:crypto').createHash('sha256').update(Buffer.from(bytes)).digest();}},getRandomValues(bytes){bytes.fill(++random);return bytes;}},setTimeout(callback,delay){const id=++nextTimer;timers.set(id,{callback,delay});return id;},clearTimeout(id){timers.delete(id);},
 fetch:async(url,options)=>{
  const payload=options.body instanceof UploadFormData?options.body:options.body?JSON.parse(options.body):null;requests.push({url,options,payload});let data;
  if(payload instanceof UploadFormData){
   if(neverUpload)return new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>{const error=new Error('Abort');error.name='AbortError';reject(error);},{once:true}));
   if(failUpload)throw new TypeError('Failed to fetch');
   data={...documentBase,scan_status:'quarantined',uploaded_by_type:'staff',uploaded_by_id:'7',version_number:2};documents=[data];cursor=null;
  }else if(payload?.action==='review'){
   data={...documents.find(d=>d.public_id===payload.document_id),review_status:payload.decision,review_notes:payload.notes,reviewed_by_type:'staff',reviewed_by_id:'7',reviewed_at:'Synthetic review time'};documents=[data];
  }else if(payload?.action==='rescan'){data={...documents[0],scan_status:'clean',review_status:'pending'};documents=[data];}
  else{const query=new URL('http://test/'+url).searchParams;if(query.get('action')==='requests')data={loan_request_id:mismatch?2:1,requester_coop_id:'B',loan_status:'submitted',terms_hash:'terms',requests:[{guarantor_request_id:11,guarantor_coop_id:'G',status:'approved'}]};
   else data=query.get('before_id')?{items:[{...documentBase,public_id:'c'.repeat(32),version_number:0,current_terms:false,is_latest:false}],next_cursor:null}:{items:documents,next_cursor:cursor};}
  return{ok:true,json:async()=>({success:true,data})};
 }});
new vm.Script(script).runInContext(context);const run=code=>vm.runInContext(code,context),flush=()=>new Promise(setImmediate);
const descendants=node=>[node,...node.children.flatMap(descendants)];
function reviewForm(){return descendants(byId('evidence-history')).find(e=>e.tagName==='FORM');}
function postCount(){return requests.filter(r=>r.payload).length;}
function chooseFile(name='proof.pdf'){byId('evidence-file').files=[{name,size:12,lastModified:100,type:'application/pdf',async arrayBuffer(){return new TextEncoder().encode(name).buffer;}}];byId('evidence-kind').value='approval';}
(async()=>{
 assert.equal(requests.length,0,'Initial page does not assume a loan or guarantor');byId('evidence-loan-id').value='1';await run("evidenceRun('evidence-association-status',evidenceLoadInvitations)");
 assert.equal(byId('evidence-invitation').value,'','Guarantor must be explicitly selected');assert(byId('evidence-loan-context').textContent.includes('Borrower B'));
 byId('evidence-invitation').value='11';await byId('evidence-invitation').fire('change');await flush();assert.equal(byId('evidence-upload-section').hidden,false);assert(reviewForm());
 let nodes=descendants(byId('evidence-history'));const download=nodes.find(n=>n.tagName==='A');assert(download.href.includes('document_id='+documentBase.public_id));assert(!download.href.includes('storage_key'));
 assert.equal(byId('evidence-older').disabled,false);await byId('evidence-older').fire('click');await flush();assert.equal(byId('evidence-history').children.length,2);assert.equal(byId('evidence-older').disabled,true);assert(requests.at(-1).url.includes('before_id=9'));
 documents=[{...documentBase,uploaded_by_type:'staff',uploaded_by_id:'7'}];cursor=null;await run("evidenceRun('evidence-history-status',evidenceLoadHistory)");assert(!reviewForm());assert(descendants(byId('evidence-history')).some(n=>n.textContent.includes('different staff member')));
 documents=[{...documentBase,current_terms:false}];await run("evidenceRun('evidence-history-status',evidenceLoadHistory)");assert(!reviewForm());assert(descendants(byId('evidence-history')).some(n=>n.textContent.includes('STALE')));
 documents=[{...documentBase,scan_status:'quarantined'}];await run("evidenceRun('evidence-history-status',evidenceLoadHistory)");nodes=descendants(byId('evidence-history'));assert(!nodes.some(n=>n.tagName==='A'));assert(!reviewForm());const scan=nodes.find(n=>n.tagName==='BUTTON'&&n.textContent==='Retry malware scan');assert(scan);await scan.fire('click');await flush();assert.equal(requests.findLast(r=>r.payload?.action==='rescan').payload.document_id,documentBase.public_id);assert(reviewForm());assert(byId('evidence-history-status').textContent.includes('never accepts evidence automatically'));
 let form=reviewForm(),decision=form.children.find(n=>n.tagName==='SELECT'),notes=form.children.find(n=>n.tagName==='TEXTAREA');decision.value='rejected';let before=postCount();await form.fire('submit');assert.equal(postCount(),before,'Rejection requires an explicit reason');notes.value='<img src=x onerror=bad()> not sufficient';await form.fire('submit');await flush();assert(!reviewForm(),'Final review cannot be changed in-place');assert(byId('evidence-history-status').focused,'Focus returns to existing status after reviewed form disappears');assert(descendants(byId('evidence-history')).some(n=>n.textContent===notes.value));
 chooseFile();failUpload=true;await byId('evidence-upload-form').fire('submit');await flush();let uploads=requests.filter(r=>r.payload instanceof UploadFormData);const key=uploads.at(-1).payload.get('upload_key');assert.match(key,/^[a-f0-9]{48}$/);assert.equal(uploads.at(-1).payload.get('guarantor_request_id'),'11');
 await byId('evidence-upload-form').fire('submit');await flush();uploads=requests.filter(r=>r.payload instanceof UploadFormData);assert.equal(uploads.at(-1).payload.get('upload_key'),key,'Uncertain upload retry preserves identity');assert.equal(byId('evidence-file').files.length,1);
 byId('evidence-kind').value='acknowledgement';before=postCount();await byId('evidence-upload-form').fire('submit');await flush();assert.equal(postCount(),before,'Changed uncertain command requires deliberate new file choice');
 chooseFile('different.pdf');await byId('evidence-file').fire('change');before=postCount();await byId('evidence-upload-form').fire('submit');await flush();assert.equal(postCount(),before,'Selecting another file cannot discard an uncertain durable intent');await byId('evidence-new-upload').fire('click');chooseFile('different.pdf');await byId('evidence-upload-form').fire('submit');await flush();uploads=requests.filter(r=>r.payload instanceof UploadFormData);assert.notEqual(uploads.at(-1).payload.get('upload_key'),key);
 failUpload=false;neverUpload=true;const pending=byId('evidence-upload-form').fire('submit');await flush();before=postCount();await byId('evidence-upload-form').fire('submit');assert.equal(postCount(),before);assert.equal(timers.size,1);const[id,timer]=[...timers.entries()][0];assert.equal(timer.delay,30000);timers.delete(id);timer.callback();await pending;await flush();assert(byId('evidence-upload-status').textContent.includes('before submitting again'));assert.equal(byId('guarantor-evidence-review').getAttribute('aria-busy'),undefined);assert.equal(byId('evidence-file').files.length,1);
 neverUpload=false;await byId('evidence-upload-form').fire('submit');await flush();assert(byId('evidence-upload-status').textContent.includes('quarantined'));assert.equal(byId('evidence-file').files.length,0);assert(!reviewForm(),'Staff uploader cannot review its own clean or quarantined proof');
 chooseFile();byId('evidence-file').files[0].size=5242881;before=postCount();await byId('evidence-upload-form').fire('submit');await flush();assert.equal(postCount(),before);
 mismatch=true;await run("evidenceRun('evidence-association-status',evidenceLoadInvitations)");assert(byId('evidence-association-status').textContent.includes('could not be verified'));assert.equal(byId('evidence-upload-section').hidden,true);
 assert(!script.includes('innerHTML'));assert(source.includes('An online response alone is insufficient'));assert(source.includes('Acknowledgement files are optional'));assert(source.includes('multipart/form-data'));
 console.log('PASS server-bound loan/guarantor association, private clean download, stale/latest/scanning state, mandatory independent staff review, immutable decisions, paginated history, rescan and stable upload retry/timeout contracts');
 console.log('DOM contract tests only; file storage/scanner/authorization are tested independently by backend suites. No rendered-browser claim.');
})().catch(error=>{console.error(error);process.exitCode=1;});

