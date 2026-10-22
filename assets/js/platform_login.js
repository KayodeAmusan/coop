(() => {
    const form=document.getElementById('platform-login-form'),username=document.getElementById('platform-username'),password=document.getElementById('platform-password'),submit=document.getElementById('platform-login-submit'),status=document.getElementById('platform-login-status');
    let busy=false;
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(busy || !form.reportValidity())return;
        const payload={username:username.value.trim(),password:password.value};password.value='';
        busy=true;submit.disabled=true;form.setAttribute('aria-busy','true');status.setAttribute('role','status');status.textContent='Signing in…';
        const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),30000);
        try {
            const response=await fetch('api/platform_login.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),signal:controller.signal});
            const result=await response.json();
            if(!response.ok || result.success!==true || result.redirect!=='platform_settings.php')throw new Error(result.message || 'Platform sign-in could not be confirmed.');
            status.textContent='Signed in. Opening deployment settings…';window.location.assign('platform_settings.php');
        }catch(error){status.setAttribute('role','alert');status.textContent=error.name==='AbortError'?'Sign-in timed out. Enter your password and try again.':error instanceof TypeError || error instanceof SyntaxError?'Platform sign-in could not be confirmed. Enter your password and try again.':error.message;status.focus();}
        finally{clearTimeout(timeout);busy=false;submit.disabled=false;form.removeAttribute('aria-busy');}
    });
})();
