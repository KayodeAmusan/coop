
const evidenceRoot = document.getElementById('guarantor-evidence-review');
const evidenceById = id => document.getElementById(id);
const evidenceMember = evidenceRoot.dataset.member === 'true'; const evidenceApi = evidenceMember ? 'auth_api/api/loans/guarantor-evidence.php' : 'auth_api/api/admin/guarantor-evidence.php'; let evidenceToken = null, evidenceMemberId = null;
let evidenceBusy = false, evidenceContext = null, evidenceNextCursor = null;
const evidenceActorId = evidenceRoot.dataset.reviewerId;
const evidenceInvitations = new Map();

function evidenceStatus(id, message, error = false) {
    const element = evidenceById(id); element.setAttribute('role', error ? 'alert' : 'status'); element.setAttribute('aria-live', error ? 'assertive' : 'polite'); element.textContent = message;
}

async function evidenceRequest(payload = null, query = null) {
    const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 30000);
    try {
        let options = {credentials: evidenceMember ? 'omit' : 'same-origin', signal: controller.signal}; if (evidenceMember) {if (!evidenceToken) throw new Error('Sign in to view evidence.'); options.headers = {Authorization: 'Bearer ' + evidenceToken};}
        if (payload) {
            options.method = 'POST'; options.body = payload instanceof FormData ? payload : JSON.stringify(payload);
            if (!(payload instanceof FormData)) options.headers = {...options.headers, 'Content-Type': 'application/json'};
        }
        const response = await fetch(query ? evidenceApi + '?' + new URLSearchParams(query) : evidenceApi, options);
        if (evidenceMember && response.status === 401) { evidenceSignOut(); throw new Error('Your member login has expired. Sign in again; check history before retrying an upload.'); } const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || result.error || 'The server could not complete the evidence request.');
        return result.data;
    } catch (error) {
        if (error && error.name === 'AbortError') throw new Error('The request took too long to confirm. Reload evidence history to check whether the upload or review completed before submitting again.');
        if (!error || ['TypeError', 'SyntaxError'].includes(error.name)) throw new Error('The evidence result is unconfirmed. Reload history before submitting an upload or review again.');
        throw error;
    } finally { clearTimeout(timeout); }
}

async function evidenceRun(statusId, operation) {
    if (evidenceBusy) return;
    evidenceBusy = true;
    const controls = Array.from(evidenceRoot.querySelectorAll('input,select,textarea,button')).map(control => [control, control.disabled]); controls.forEach(([control]) => {control.disabled = true;});
    evidenceRoot.setAttribute('aria-busy', 'true'); evidenceStatus(statusId, 'Working…');
    try { await operation(); }
    catch (error) { evidenceStatus(statusId, error instanceof Error ? error.message : 'The result is unconfirmed. Reload evidence before trying again.', true); }
    finally {
        controls.forEach(([control, disabled]) => {control.disabled = disabled;});
        evidenceBusy = false; evidenceRoot.removeAttribute('aria-busy');
        evidenceById('evidence-invitation').disabled = evidenceInvitations.size === 0;
        evidenceById('evidence-older').disabled = !evidenceNextCursor;
        (evidenceMember && !evidenceToken ? evidenceById('member-username') : evidenceMember && statusId === 'member-login-status' ? evidenceById('member-loan-choice') : evidenceById(statusId) || evidenceById('evidence-history-status')).focus();
    }
}

function evidenceSelectPlaceholder(text) {
    const select = evidenceById('evidence-invitation'); select.replaceChildren();
    const option = document.createElement('option'); option.value = ''; option.textContent = text; select.appendChild(option); select.value = '';
}

async function evidenceLoadInvitations() {
    const loanId = evidenceById('evidence-loan-id').value.trim();
    if (!/^[1-9][0-9]{0,9}$/.test(loanId) || Number(loanId) > 2147483647) throw new Error('Enter a valid loan request reference.');
    evidenceContext = null; evidenceInvitations.clear(); evidenceSelectPlaceholder('Choose the associated guarantor invitation');
    evidenceById('evidence-upload-section').hidden = true; evidenceById('evidence-history-section').hidden = true; evidenceById('evidence-history').replaceChildren(); evidenceById('evidence-loan-context').textContent = '';
    const data = await evidenceRequest(null, {action: 'requests', loan_request_id: loanId});
    if (String(data.loan_request_id) !== loanId || !Array.isArray(data.requests)) throw new Error('The server loan association could not be verified.');
    evidenceContext = data;
    evidenceById('evidence-loan-context').textContent = 'Loan request ' + data.loan_request_id + ' · Borrower ' + data.requester_coop_id + ' · Loan status ' + data.loan_status;
    const select = evidenceById('evidence-invitation');
    for (const invitation of data.requests) {
        const id = String(invitation.guarantor_request_id); evidenceInvitations.set(id, invitation);
        const option = document.createElement('option'); option.value = id; option.textContent = 'Guarantor ' + invitation.guarantor_coop_id + ' · Invitation ' + id + ' (' + invitation.status + ')'; select.appendChild(option);
    }
    select.value = '';
    evidenceStatus('evidence-association-status', data.requests.length ? 'Loan association loaded. Choose its guarantor invitation explicitly.' : 'No guarantor invitations are associated with this loan.');
}

function evidenceCurrentInvitation() {
    const id = evidenceById('evidence-invitation').value;
    if (!evidenceContext || !evidenceInvitations.has(id)) throw new Error('Load the loan and choose one of its actual guarantor invitations first.');
    return evidenceInvitations.get(id);
}

function evidenceAddDetail(list, label, value) {
    const term = document.createElement('dt'); term.textContent = label; const description = document.createElement('dd'); description.textContent = value == null || value === '' ? 'Not recorded' : String(value); list.appendChild(term); list.appendChild(description);
}

function evidenceRenderDocument(item, invitation) {
    if (!/^[a-f0-9]{32}$/.test(item.public_id) || String(item.guarantor_request_id) !== String(invitation.guarantor_request_id) || String(item.loan_request_id) !== String(evidenceContext.loan_request_id) || String(item.guarantor_coop_id) !== String(invitation.guarantor_coop_id)) throw new Error('An evidence association could not be verified. Reload the selected invitation.');
    const entry = document.createElement('li'); entry.className = 'evidence-card';
    const heading = document.createElement('h3'); heading.textContent = (item.evidence_kind === 'approval' ? 'Signed approval' : 'Supporting acknowledgement') + ' · Version ' + item.version_number; entry.appendChild(heading);
    const details = document.createElement('dl');
    evidenceAddDetail(details, 'Scanning', item.scan_status); evidenceAddDetail(details, 'Staff review', item.review_status);
    evidenceAddDetail(details, 'Loan terms', item.current_terms === true ? 'Current terms' : 'STALE — superseded terms; cannot qualify for approval');
    evidenceAddDetail(details, 'Version', item.is_latest === true ? 'Latest version of this evidence category' : 'Superseded version; cannot qualify for approval');
    evidenceAddDetail(details, 'Uploaded by', item.uploaded_by_type + ' ' + item.uploaded_by_id + ' · ' + item.uploaded_at);
    evidenceAddDetail(details, 'Reviewed by', item.reviewed_by_id ? item.reviewed_by_type + ' ' + item.reviewed_by_id + ' · ' + item.reviewed_at : null);
    evidenceAddDetail(details, 'Review notes', item.review_notes); evidenceAddDetail(details, 'File type / bytes', item.mime_type + ' / ' + item.byte_size);
    evidenceAddDetail(details, 'Content SHA-256', item.content_sha256); evidenceAddDetail(details, 'Bound terms hash', item.terms_hash); entry.appendChild(details);
    if (item.scan_status === 'clean') {
        const download = document.createElement('a'); download.className = 'evidence-download'; download.textContent = 'Download private evidence version ' + item.version_number;
        download.href = evidenceApi + '?' + new URLSearchParams({action: 'download', document_id: item.public_id}); if (evidenceMember) download.addEventListener('click', event => {event.preventDefault(); evidenceRun('evidence-history-status', () => evidenceDownload(item));}); entry.appendChild(download);
    } else { const blocked = document.createElement('p'); blocked.textContent = 'Download and staff review are blocked until configured malware scanning marks this file clean.'; entry.appendChild(blocked); }
    const independentReviewer = !(item.uploaded_by_type === 'staff' && String(item.uploaded_by_id) === evidenceActorId);
    if (!evidenceMember && item.scan_status === 'clean' && item.current_terms === true && item.is_latest === true && item.review_status === 'pending' && independentReviewer) {
        const form = document.createElement('form'); const decisionId = 'evidence-decision-' + item.public_id; const notesId = 'evidence-notes-' + item.public_id; const statusId = 'evidence-review-status-' + item.public_id;
        const label = document.createElement('label'); label.htmlFor = decisionId; label.textContent = 'Staff review decision for version ' + item.version_number + ' (required)'; form.appendChild(label);
        const decision = document.createElement('select'); decision.id = decisionId; decision.required = true;
        for (const [value, text] of [['', 'Choose acceptance or rejection'], ['accepted', 'Accept this evidence after staff review'], ['rejected', 'Reject this evidence']]) {const option = document.createElement('option'); option.value = value; option.textContent = text; decision.appendChild(option);} decision.value = ''; form.appendChild(decision);
        const notesLabel = document.createElement('label'); notesLabel.htmlFor = notesId; notesLabel.textContent = 'Staff review notes (reason required for rejection)'; form.appendChild(notesLabel);
        const notes = document.createElement('textarea'); notes.id = notesId; notes.maxLength = 4000; form.appendChild(notes);
        const submit = document.createElement('button'); submit.type = 'submit'; submit.textContent = 'Record staff evidence review'; form.appendChild(submit);
        const status = document.createElement('p'); status.id = statusId; status.className = 'evidence-status'; status.tabIndex = -1; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); form.appendChild(status);
        form.addEventListener('submit', event => {
            event.preventDefault(); if (evidenceBusy || !form.reportValidity()) return;
            if (decision.value === 'rejected' && !notes.value.trim()) {evidenceStatus(statusId, 'A reason is required when rejecting evidence.', true); status.focus(); return;}
            const payload = {action: 'review', document_id: item.public_id, decision: decision.value, notes: notes.value.trim()};
            evidenceRun(statusId, async () => {
                const reviewed = await evidenceRequest(payload);
                if (reviewed.public_id !== item.public_id || reviewed.review_status !== payload.decision) throw new Error('The recorded review could not be verified. Reload evidence history.');
                evidenceStatus(statusId, 'Staff evidence review recorded as ' + reviewed.review_status + '. This does not approve the loan.');
                try { await evidenceLoadHistory(); evidenceStatus('evidence-history-status', 'Staff evidence review recorded as ' + reviewed.review_status + '. This does not approve the loan.'); } catch (error) {evidenceStatus('evidence-history-status', 'The review was recorded, but history could not refresh. Reload history to check the current evidence state.', true);}
            });
        }); entry.appendChild(form);
    } else {
        const unavailable = document.createElement('p');
        unavailable.textContent = evidenceMember ? 'Staff review is required. Uploading or downloading this document does not accept it or approve the loan.' : !independentReviewer
            ? 'A different staff member must review a staff-uploaded document.'
            : item.review_status !== 'pending' ? 'This staff review is final for this version. A different decision requires a new evidence version.'
            : 'This version cannot be accepted or used for loan approval while it is unclean, stale or superseded.';
        entry.appendChild(unavailable);
    }
    if (!evidenceMember && item.scan_status === 'quarantined' && item.review_status !== 'accepted') {
        const rescan = document.createElement('button'); rescan.type = 'button'; rescan.textContent = 'Retry malware scan';
        rescan.addEventListener('click', () => evidenceRun('evidence-history-status', async () => {
            const scanned = await evidenceRequest({action: 'rescan', document_id: item.public_id});
            if (scanned.public_id !== item.public_id) throw new Error('The scan result could not be verified. Reload evidence history.');
            await evidenceLoadHistory();
            evidenceStatus('evidence-history-status', 'Scan result: ' + scanned.scan_status + '. Staff review remains pending; scanning never accepts evidence automatically.');
        }));
        entry.appendChild(rescan);
    }
    return entry;
}

async function evidenceLoadHistory(older = false) {
    const invitation = evidenceCurrentInvitation();
    const query = {guarantor_request_id: invitation.guarantor_request_id, limit: 20};
    if (older) { if (!evidenceNextCursor) return; query.before_id = evidenceNextCursor; }
    const data = await evidenceRequest(null, query);
    if (!data || !Array.isArray(data.items) || (data.next_cursor !== null && !/^[1-9][0-9]*$/.test(String(data.next_cursor)))) throw new Error('Evidence history could not be verified.');
    const entries = data.items.map(item => evidenceRenderDocument(item, invitation));
    const history = evidenceById('evidence-history');
    if (!older) history.replaceChildren();
    entries.forEach(entry => history.appendChild(entry));
    evidenceNextCursor = data.next_cursor;
    evidenceStatus('evidence-history-status', history.children.length ? 'Evidence history loaded. Check current terms, latest version, scanning and staff review together.' : 'No evidence has been uploaded for this guarantor invitation. Signed approval remains required.');
}

evidenceById('evidence-association-form').addEventListener('submit', event => {event.preventDefault(); if (!evidenceBusy && evidenceById('evidence-association-form').reportValidity()) evidenceRun('evidence-association-status', evidenceLoadInvitations);});
evidenceById('evidence-invitation').addEventListener('change', () => {
    if (evidenceBusy) return;
    const invitation = evidenceInvitations.get(evidenceById('evidence-invitation').value);
    evidenceById('evidence-upload-section').hidden = !invitation; evidenceById('evidence-history-section').hidden = !invitation;
    evidenceById('evidence-upload-form').reset(); evidenceNextCursor = null; evidenceById('evidence-history').replaceChildren();
    if (!invitation) return;
    evidenceById('evidence-invitation-context').textContent = 'Upload association: loan ' + evidenceContext.loan_request_id + ' · Guarantor ' + invitation.guarantor_coop_id + ' · Invitation ' + invitation.guarantor_request_id + ' (' + invitation.status + ')';
    evidenceRun('evidence-history-status', evidenceLoadHistory);
});
evidenceById('evidence-reload').addEventListener('click', () => evidenceRun('evidence-history-status', evidenceLoadHistory));
evidenceById('evidence-older').addEventListener('click', () => evidenceRun('evidence-history-status', () => evidenceLoadHistory(true)));
if (!evidenceMember && evidenceById('evidence-loan-id').value) evidenceRun('evidence-association-status', evidenceLoadInvitations);

const evidenceIntentStorageKey = 'coop.evidence.upload.intent.v1';
function evidenceStoredIntent() {
    try {
        const raw = sessionStorage.getItem(evidenceIntentStorageKey);
        if (!raw) return null;
        const intent = JSON.parse(raw);
        if (!/^[a-f0-9]{64}$/.test(intent.fingerprint) || !/^[a-f0-9]{48}$/.test(intent.key)) throw new Error();
        return intent;
    } catch (error) { throw new Error('Safe upload retry storage is unavailable. Restore this tab storage before uploading; check history if a previous result was uncertain.'); }
}
async function evidenceDigest(bytes) {
    if (!globalThis.crypto?.subtle || !globalThis.crypto.getRandomValues) throw new Error('Secure file fingerprinting requires HTTPS or a trusted localhost connection.');
    return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), byte => byte.toString(16).padStart(2,'0')).join('');
}
async function evidencePrepareUpload() {
    const invitation=evidenceCurrentInvitation(), file=evidenceById('evidence-file').files[0],kind=evidenceById('evidence-kind').value;
    if (!file || file.size<1 || file.size>5242880) throw new Error('Choose a nonempty PDF, JPEG or PNG file of at most 5 MiB.');
    if (!['approval','acknowledgement'].includes(kind)) throw new Error('Choose the evidence category explicitly.');
    const content=await evidenceDigest(await file.arrayBuffer());
    const fingerprint=await evidenceDigest(new TextEncoder().encode(JSON.stringify([evidenceMember?'member':'staff',evidenceMember?evidenceMemberId:evidenceActorId,String(evidenceContext.loan_request_id),String(invitation.guarantor_request_id),kind,evidenceContext.terms_hash,content])));
    let intent=evidenceStoredIntent();
    if (intent && intent.fingerprint!==fingerprint) throw new Error('A different upload is still unconfirmed. Check its history, then choose Start a different upload explicitly.');
    if (!intent) {
        const bytes=new Uint8Array(24);crypto.getRandomValues(bytes);
        intent={fingerprint,key:Array.from(bytes,byte=>byte.toString(16).padStart(2,'0')).join('')};
        try {sessionStorage.setItem(evidenceIntentStorageKey,JSON.stringify(intent));if(sessionStorage.getItem(evidenceIntentStorageKey)!==JSON.stringify(intent))throw new Error();}
        catch(error){throw new Error('Safe upload retry storage is unavailable. No upload was submitted.');}
    }
    const payload=new FormData(evidenceById('evidence-upload-form'));
    payload.append('action','upload');payload.append('guarantor_request_id',invitation.guarantor_request_id);payload.append('upload_key',intent.key);
    return payload;
}
evidenceById('evidence-upload-form').addEventListener('submit', event => {
    event.preventDefault();if(evidenceBusy || !evidenceById('evidence-upload-form').reportValidity())return;
    evidenceRun('evidence-upload-status',async()=>{
        const payload=await evidencePrepareUpload(),uploaded=await evidenceRequest(payload);
        if(String(uploaded.guarantor_request_id)!==String(payload.get('guarantor_request_id')))throw new Error('The upload association could not be verified. Reload history before uploading again.');
        try {sessionStorage.removeItem(evidenceIntentStorageKey);} catch(error) {throw new Error('The upload completed, but its retry record could not be cleared. Reload history before starting another upload.');}
        evidenceById('evidence-upload-form').reset();
        evidenceStatus('evidence-upload-status','Evidence version '+uploaded.version_number+' uploaded. Scanning: '+uploaded.scan_status+'; staff review: '+uploaded.review_status+'. Upload does not qualify as acceptance or loan approval.');
        try {await evidenceLoadHistory();}catch(error){evidenceStatus('evidence-history-status','The upload completed, but history could not refresh. Reload history before another upload.',true);}
    });
});
evidenceById('evidence-new-upload').addEventListener('click',()=>{
    if(evidenceBusy || !confirm('Start a different upload? First check history for any unconfirmed upload. A new upload can supersede previously accepted evidence.'))return;
    try {sessionStorage.removeItem(evidenceIntentStorageKey);evidenceById('evidence-upload-form').reset();evidenceStatus('evidence-upload-status','New upload started. Choose its category and file explicitly.');}
    catch(error){evidenceStatus('evidence-upload-status','Retry storage could not be cleared. No new upload was started.',true);}
});
evidenceById('evidence-file').addEventListener('change',()=>evidenceStatus('evidence-upload-status','The selected file will be fingerprinted. An unconfirmed matching upload reuses its saved reference, including after reload.'));

function evidenceSignOut() {
    evidenceToken=null;evidenceMemberId=null;evidenceContext=null;evidenceInvitations.clear();evidenceNextCursor=null;
    evidenceById('member-password').value='';evidenceById('member-identity').textContent='';
    evidenceById('member-login-section').hidden=false;evidenceById('member-workspace').hidden=true;
    evidenceById('evidence-upload-form').reset();evidenceById('evidence-history').replaceChildren();
    evidenceById('evidence-upload-section').hidden=true;evidenceById('evidence-history-section').hidden=true;
    evidenceById('evidence-loan-context').textContent='';evidenceSelectPlaceholder('Sign in and choose a loan');
    evidenceStatus('member-login-status','Sign in to continue. If an upload was unconfirmed, check its history before retrying.');
    evidenceById('member-username').focus();
}
async function evidenceMemberFetch(url) {
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),30000);
    try {
        const response=await fetch(url,{credentials:'omit',headers:{Authorization:'Bearer '+evidenceToken},signal:controller.signal});
        if(response.status===401){evidenceSignOut();throw new Error('Your member login expired. Sign in again.');}
        const result=await response.json();if(!response.ok || result.success!==true || !Array.isArray(result.data))throw new Error('Your loan references could not be loaded. Retry loading your loans.');
        return result.data;
    }catch(error){if(error.name==='AbortError')throw new Error('Loading your loans timed out. Try loading them again.');throw error;}
    finally{clearTimeout(timeout);}
}
async function evidenceDiscoverLoans() {
    const select=evidenceById('member-loan-choice');select.replaceChildren();const blank=document.createElement('option');blank.value='';blank.textContent='Choose one of your loans or invitations';select.appendChild(blank);select.value='';
    const loans=await evidenceMemberFetch('auth_api/api/loans/request.php?'+new URLSearchParams({requester_coop_id:evidenceMemberId}));
    const invitations=await evidenceMemberFetch('auth_api/api/loans/guarantor-request.php?'+new URLSearchParams({guarantor_coop_id:evidenceMemberId}));
    const choices=new Map();for(const loan of loans)choices.set(String(loan.id),'Your loan '+loan.id+' · '+loan.status);
    for(const invitation of invitations){const id=String(invitation.loan_request_id);if(!choices.has(id))choices.set(id,'Guarantor invitation for loan '+id+' · '+invitation.loan_status);}
    for(const[id,label]of choices){if(!/^[1-9][0-9]*$/.test(id))continue;const option=document.createElement('option');option.value=id;option.textContent=label;select.appendChild(option);}
    evidenceStatus('member-workspace-status',choices.size?'Choose a loan, then load its server-verified guarantor invitations.':'No loans or guarantor invitations were returned for your member account.');
}
async function evidenceDownload(item) {
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),30000);
    try {
        if(!evidenceToken)throw new Error('Sign in before downloading private evidence.');
        const response=await fetch(evidenceApi+'?'+new URLSearchParams({action:'download',document_id:item.public_id}),{credentials:'omit',headers:{Authorization:'Bearer '+evidenceToken},signal:controller.signal});
        if(response.status===401){evidenceSignOut();throw new Error('Your member login expired. Sign in again.');}
        if(!response.ok){const result=await response.json();throw new Error(result.message||'The private download is unavailable.');}
        const blob=await response.blob();if(!['application/pdf','image/jpeg','image/png'].includes(blob.type))throw new Error('The download type could not be verified.');
        const url=URL.createObjectURL(blob),link=document.createElement('a');
        try {link.href=url;link.download='guarantor-evidence-'+item.public_id+({'application/pdf':'.pdf','image/jpeg':'.jpg','image/png':'.png'}[blob.type]);document.body.appendChild(link);link.click();link.remove();}
        finally{URL.revokeObjectURL(url);}
        evidenceStatus('evidence-history-status','Private download requested. This does not accept the evidence.');
    }catch(error){if(error.name==='AbortError')throw new Error('The download timed out. You can request this evidence version again.');throw error;}
    finally{clearTimeout(timeout);}
}
if(evidenceMember) {
    evidenceById('member-signout').addEventListener('click',()=>{if(!evidenceBusy){evidenceSignOut();evidenceStatus('member-login-status','Signed out of this page. Unconfirmed upload references remain in this tab so safe retry can continue after sign-in.');}});
    evidenceById('member-refresh-loans').addEventListener('click',()=>evidenceRun('member-workspace-status',evidenceDiscoverLoans));
    evidenceById('member-loan-choice').addEventListener('change',()=>{if(!evidenceBusy){evidenceById('evidence-loan-id').value=evidenceById('member-loan-choice').value;}});
    evidenceById('member-login-form').addEventListener('submit',event=>{
        event.preventDefault();if(evidenceBusy || !evidenceById('member-login-form').reportValidity())return;
        const username=evidenceById('member-username').value.trim(),password=evidenceById('member-password').value;evidenceById('member-password').value='';
        evidenceById('member-login-submit').disabled=true;
        evidenceRun('member-login-status',async()=>{
            const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),30000);
            try {
                const response=await fetch('auth_api/api/auth/login.php',{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json'},body:JSON.stringify({username,password}),signal:controller.signal});
                const result=await response.json();
                if(!response.ok || result.success!==true || typeof result.token!=='string' || !result.token || typeof result.user?.CoopID!=='string' || !result.user.CoopID)throw new Error('Member sign-in failed. Check your credentials and try again.');
                evidenceToken=result.token;evidenceMemberId=result.user.CoopID;
                evidenceById('member-identity').textContent='Signed in as '+result.user.CoopID;evidenceById('member-login-section').hidden=true;evidenceById('member-workspace').hidden=false;
                try{await evidenceDiscoverLoans();}catch(error){evidenceStatus('member-workspace-status',error.message,true);}
                evidenceById('member-loan-choice').focus();
            }catch(error){if(error.name==='AbortError')throw new Error('Sign-in timed out. Enter your password and try again.');throw error;}
            finally{clearTimeout(timeout);evidenceById('member-login-submit').disabled=false;}
        });
    });
}
