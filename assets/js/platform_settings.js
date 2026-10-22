(() => {
    const root=document.getElementById('platform-settings'),byId=id=>document.getElementById(id),intentPrefix='coop.platform.command.v1.';
    const schemas={smtp:{secret:'password',fields:['host','port','encryption','username','from_email','from_name']},paystack:{secret:'secret_key',fields:['mode','public_key']},sms:{secret:'api_key',fields:['adapter','endpoint_url','http_method','auth_style','auth_field','sender_id','recipient_field','message_field','sender_field']}};
    let busy=false,settings=null,assets={logo:null,favicon:null};
    function status(id,message,error=false){const node=byId(id);node.textContent=message;node.setAttribute('role',error?'alert':'status');}
    async function request(payload=null,url='api/platform_settings.php'){
        const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),30000);
        try{
            const options={credentials:'same-origin',signal:controller.signal};
            if(payload){options.method='POST';options.body=payload instanceof FormData?payload:JSON.stringify(payload);if(!(payload instanceof FormData))options.headers={'Content-Type':'application/json'};}
            const response=await fetch(url,options),result=await response.json();
            if(!response.ok || result.success!==true)throw new Error(result.message || 'The settings request could not be confirmed. Sign in again if access has expired.');
            return result;
        }catch(error){if(error.name==='AbortError')throw new Error('The request timed out. Reload saved settings to verify the outcome before retrying with the same values.');if(error instanceof TypeError || error instanceof SyntaxError)throw new Error('The result is unconfirmed. Reload saved settings before retrying with the same values.');throw error;}
        finally{clearTimeout(timeout);}
    }
    async function run(id,operation){
        if(busy)return;busy=true;root.setAttribute('aria-busy','true');
        const controls=Array.from(root.querySelectorAll('button,input,select,textarea')).map(node=>[node,node.disabled]);controls.forEach(([node])=>node.disabled=true);status(id,'Working…');
        try{await operation();}catch(error){status(id,error.message || 'The result is unconfirmed. Reload saved settings.',true);}
        finally{controls.forEach(([node,disabled])=>node.disabled=disabled);busy=false;root.removeAttribute('aria-busy');for(const provider of Object.keys(schemas))byId(provider+'-secret-value').disabled=byId(provider+'-secret-action').value!=='replace';byId(id).focus();}
    }
    function stored(scope){try{const value=sessionStorage.getItem(intentPrefix+scope);if(!value)return null;const intent=JSON.parse(value);if(!/^[a-f0-9]{48}$/.test(intent.key)||!/^[a-f0-9]{64}$/.test(intent.fingerprint)||!Number.isInteger(intent.expectedRevision)||intent.expectedRevision<0)throw new Error();return intent;}catch(error){throw new Error('Safe retry storage is unavailable. Restore this tab storage before saving.');}}
    async function digest(bytes){if(!crypto?.subtle || !crypto.getRandomValues)throw new Error('Secure retry references require HTTPS or trusted localhost.');return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes)),n=>n.toString(16).padStart(2,'0')).join('');}
    async function command(scope,payload,revision){
        const existing=stored(scope),expectedRevision=existing?existing.expectedRevision:revision;
        payload.expected_revision=expectedRevision;
        const fingerprint=await digest(new TextEncoder().encode(JSON.stringify([root.dataset.actorId,scope,payload])));
        if(existing && existing.fingerprint!==fingerprint)throw new Error('An earlier save is unconfirmed with different values. Check saved settings, then explicitly start a different save.');
        let intent=existing;
        if(!intent){const bytes=new Uint8Array(24);crypto.getRandomValues(bytes);intent={key:Array.from(bytes,n=>n.toString(16).padStart(2,'0')).join(''),fingerprint,expectedRevision};try{sessionStorage.setItem(intentPrefix+scope,JSON.stringify(intent));if(sessionStorage.getItem(intentPrefix+scope)!==JSON.stringify(intent))throw new Error();}catch(error){throw new Error('Safe retry storage is unavailable. Nothing was submitted.');}}
        payload.command_key=intent.key;return payload;
    }
    function clearIntent(scope){try{sessionStorage.removeItem(intentPrefix+scope);}catch(error){throw new Error('The save completed, but its retry record could not be cleared. Reload saved settings before starting another save.');}}
    function assetUrl(url){if(typeof url!=='string'||!/^\/?api\/branding_asset\.php\?id=[a-f0-9]{32}$/.test(url))return null;return url;}
    function showAsset(kind,id,url){assets[kind]=id||null;byId('brand-'+kind+'-state').textContent=id?'Selected asset '+id+' (branding save required for changes)':'No asset selected';const image=byId('brand-'+kind+'-preview'),safe=assetUrl(url);image.hidden=!safe;if(safe)image.src=safe;else image.removeAttribute('src');}
    function preview(){const primary=byId('brand-primary').value,accent=byId('brand-accent').value;if(/^#[a-f0-9]{6}$/i.test(primary))byId('brand-preview').style.borderLeftColor=primary;if(/^#[a-f0-9]{6}$/i.test(accent))byId('brand-preview').style.borderTopColor=accent;byId('brand-preview-name').textContent=byId('brand-name').value||'Cooperative identity preview';}
    async function load(){
        const result=await request(),data=result.data;
        if(!data?.branding || !data.integrations || !Number.isInteger(data.branding.revision))throw new Error('Saved settings could not be verified.');
        for(const provider of Object.keys(schemas))if(!data.integrations[provider] || !Number.isInteger(data.integrations[provider].revision))throw new Error('Integration settings could not be verified.');
        settings=data;byId('brand-name').value=data.branding.cooperative_name||'';byId('brand-primary').value=data.branding.primary_color||'';byId('brand-accent').value=data.branding.accent_color||'';
        for(const kind of ['logo','favicon'])showAsset(kind,data.branding[kind+'_asset_id'],data.branding[kind+'_url']);preview();
        for(const[provider,schema]of Object.entries(schemas)){const integration=data.integrations[provider];byId(provider+'-enabled').checked=integration.enabled===true;for(const field of schema.fields)byId(provider+'-'+field).value=integration.config?.[field]??'';byId(provider+'-secret-state').textContent=integration.secrets?.[schema.secret]?.configured?'A secret is stored. Its value is hidden.':'No secret is stored.';}
        byId('platform-branding-fields').disabled=false;root.querySelectorAll('[data-settings-fields]').forEach(node=>node.disabled=false);
        status('platform-status','Saved settings loaded. Configuration validation and live connectivity are separate checks.');
    }
    byId('platform-branding-form').addEventListener('submit',event=>{event.preventDefault();if(busy || !settings || !event.currentTarget.reportValidity())return;run('branding-status',async()=>{
        const payload=await command('branding',{action:'save_branding',branding:{cooperative_name:byId('brand-name').value.trim(),primary_color:byId('brand-primary').value,accent_color:byId('brand-accent').value,logo_asset_id:assets.logo,favicon_asset_id:assets.favicon}},settings.branding.revision);
        await request(payload);clearIntent('branding');status('branding-status','Branding saved. Reload cooperative pages to see the updated identity.');try{await load();}catch(error){status('platform-status','Branding was saved, but saved settings could not refresh. Reload before another change.',true);}
    });});
    for(const kind of ['logo','favicon'])byId('platform-asset-'+kind).addEventListener('submit',event=>{event.preventDefault();if(busy || !settings || !event.currentTarget.reportValidity())return;run('asset-'+kind+'-status',async()=>{
        const file=byId('asset-'+kind).files[0];if(!file || file.size<1 || file.size>5242880)throw new Error('Choose a nonempty supported image up to 5 MiB.');
        const intent=await command('asset_'+kind,{asset_kind:kind,content_sha256:await digest(await file.arrayBuffer())},0),form=new FormData();form.append('asset_kind',kind);form.append('asset',file);form.append('upload_key',intent.command_key);
        const result=await request(form,'api/platform_branding_upload.php'),asset=result.data;if(!asset||!/^[a-f0-9]{32}$/.test(asset.asset_id)||!assetUrl(asset.url))throw new Error('The uploaded asset could not be verified. Retry the same file.');
        clearIntent('asset_'+kind);showAsset(kind,asset.asset_id,asset.url);byId('asset-'+kind).value='';status('asset-'+kind+'-status','Image uploaded. Save branding to apply it.');
    });});
    for(const[provider,schema]of Object.entries(schemas)){
        byId(provider+'-secret-action').addEventListener('change',()=>{const action=byId(provider+'-secret-action').value;byId(provider+'-secret-value').disabled=action!=='replace';if(action!=='replace')byId(provider+'-secret-value').value='';});
        byId('platform-integration-'+provider).addEventListener('submit',event=>{event.preventDefault();if(busy || !settings || !event.currentTarget.reportValidity())return;run(provider+'-status',async()=>{
            const config={};for(const field of schema.fields)config[field]=field==='port'?Number(byId(provider+'-'+field).value):byId(provider+'-'+field).value;
            const action=byId(provider+'-secret-action').value,secret={action};if(action==='replace'){secret.value=byId(provider+'-secret-value').value;if(!secret.value)throw new Error('Enter the replacement secret explicitly.');}
            if(!['retain','replace','clear'].includes(action))throw new Error('Choose how to handle the stored secret.');
            const payload=await command(provider,{action:'save_integration',provider,enabled:byId(provider+'-enabled').checked,config,secrets:{[schema.secret]:secret}},settings.integrations[provider].revision);
            await request(payload);clearIntent(provider);byId(provider+'-secret-value').value='';byId(provider+'-secret-action').value='retain';
            status(provider+'-status',provider==='sms'?'SMS configuration saved. Sending remains unavailable until the adapter is implemented.':'Configuration saved. A successful save does not establish live connectivity.');try{await load();}catch(error){status('platform-status','Configuration was saved, but saved settings could not refresh. Reload before another change.',true);}
        });});
    }
    root.querySelectorAll('[data-clear-asset]').forEach(button=>button.addEventListener('click',()=>{if(!busy)showAsset(button.dataset.clearAsset,null,null);}));
    root.querySelectorAll('[data-new-command]').forEach(button=>button.addEventListener('click',()=>{if(busy || !confirm('Start a different command? First reload saved settings and check whether the earlier request completed.'))return;try{sessionStorage.removeItem(intentPrefix+button.dataset.newCommand);status('platform-status','A different command may now be submitted. Review every value before saving.');}catch(error){status('platform-status','Retry storage could not be cleared. No new command was started.',true);}}));
    root.querySelectorAll('[data-test-provider]').forEach(button=>button.addEventListener('click',()=>run(button.dataset.testProvider+'-status',async()=>{const result=await request({action:'test_connection',provider:button.dataset.testProvider}),data=result.data;if(data?.network_tested!==true){status(button.dataset.testProvider+'-status','A live connection was not tested. Configuration validation does not establish connectivity.');return;}status(button.dataset.testProvider+'-status',data.connected===true?'Saved configuration connected successfully. No message or payment was sent.':'The saved configuration did not connect successfully.',data.connected!==true);}))); 
    for(const id of ['brand-name','brand-primary','brand-accent'])byId(id).addEventListener('input',preview);
    byId('platform-reload').addEventListener('click',()=>run('platform-status',load));
    byId('platform-signout').addEventListener('click',()=>run('platform-status',async()=>{for(const provider of Object.keys(schemas))byId(provider+'-secret-value').value='';await request({},'api/platform_logout.php');window.location.assign('platform_login.php');}));
    run('platform-status',load);
})();
