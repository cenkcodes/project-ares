<style>
.xv-consent{position:fixed;inset:auto 18px 18px 18px;z-index:9999;display:none;max-width:920px;margin:0 auto;padding:20px;border:1px solid #333;border-radius:12px;background:#111;box-shadow:0 18px 60px rgba(0,0,0,.55);color:#fff}.xv-consent.is-visible{display:block}.xv-consent__title{margin:0 0 8px;font-size:18px;font-weight:800}.xv-consent__text{margin:0;color:#aaa;font-size:13px;line-height:1.65}.xv-consent__text a{color:#fff;text-decoration:underline}.xv-consent__actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}.xv-consent__button{min-height:40px;padding:0 15px;border:1px solid #3a3a3a;border-radius:7px;background:#1b1b1b;color:#fff;font-weight:700;cursor:pointer}.xv-consent__button--primary{border-color:#fff;background:#fff;color:#111}.xv-consent__preferences{display:none;margin-top:18px;padding-top:16px;border-top:1px solid #2b2b2b}.xv-consent__preferences.is-visible{display:block}.xv-consent__row{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:12px 0;border-bottom:1px solid #202020}.xv-consent__row:last-child{border-bottom:0}.xv-consent__row-title{font-weight:700}.xv-consent__row-copy{margin-top:4px;color:#888;font-size:12px;line-height:1.5}.xv-consent__toggle{flex-shrink:0;width:20px;height:20px;accent-color:#fff}.xv-cookie-settings-fixed{position:fixed;left:12px;bottom:12px;z-index:9980;padding:7px 10px;border:1px solid #333;border-radius:7px;background:rgba(17,17,17,.92);color:#aaa;font-size:11px;cursor:pointer}.xv-cookie-settings-fixed:hover{color:#fff}@media(max-width:650px){.xv-consent{inset:auto 10px 10px 10px}.xv-consent__actions{display:grid;grid-template-columns:1fr}.xv-consent__button{width:100%}}
</style>

<button type="button" class="xv-cookie-settings-fixed" data-xurvexa-cookie-settings>Cookie Settings</button>

<div id="xv-consent" class="xv-consent" role="dialog" aria-labelledby="xv-consent-title">
    <h2 id="xv-consent-title" class="xv-consent__title">Privacy choices</h2>
    <p class="xv-consent__text">Xurvexa uses necessary technologies for security, sessions, age-gate state and privacy choices. Optional analytics and advertising technologies are used only according to your preferences and applicable law. <a href="{{ route('pages.cookie-policy') }}">Cookie Policy</a></p>

    <div id="xv-consent-preferences" class="xv-consent__preferences">
        <div class="xv-consent__row"><div><div class="xv-consent__row-title">Necessary</div><div class="xv-consent__row-copy">Required for security, sessions, age-gate state and privacy settings.</div></div><input class="xv-consent__toggle" type="checkbox" checked disabled aria-label="Necessary cookies enabled"></div>
        <div class="xv-consent__row"><div><div class="xv-consent__row-title">Analytics</div><div class="xv-consent__row-copy">Helps measure service performance when analytics is enabled.</div></div><input id="xv-consent-analytics" class="xv-consent__toggle" type="checkbox" aria-label="Allow analytics"></div>
        <div class="xv-consent__row"><div><div class="xv-consent__row-title">Advertising</div><div class="xv-consent__row-copy">Allows optional advertising cookies, local storage or similar technologies when an advertising provider is enabled.</div></div><input id="xv-consent-advertising" class="xv-consent__toggle" type="checkbox" aria-label="Allow advertising"></div>
    </div>

    <div class="xv-consent__actions">
        <button type="button" class="xv-consent__button" data-xv-consent-action="reject">Reject optional</button>
        <button type="button" class="xv-consent__button" data-xv-consent-action="manage">Manage preferences</button>
        <button type="button" class="xv-consent__button" data-xv-consent-action="save" hidden>Save preferences</button>
        <button type="button" class="xv-consent__button xv-consent__button--primary" data-xv-consent-action="accept">Accept all</button>
    </div>
</div>

<script>
(()=>{'use strict';
const VERSION='2026-08-27',KEY='xurvexa_consent_v1',root=document.getElementById('xv-consent'),prefs=document.getElementById('xv-consent-preferences'),analytics=document.getElementById('xv-consent-analytics'),advertising=document.getElementById('xv-consent-advertising'),manage=root.querySelector('[data-xv-consent-action="manage"]'),save=root.querySelector('[data-xv-consent-action="save"]'),csrf=@json(csrf_token()),endpoint=@json(route('privacy.consent.store'));
const read=()=>{try{const v=localStorage.getItem(KEY);if(!v)return null;const p=JSON.parse(v);return p&&p.version===VERSION?p:null}catch(e){return null}};
const cookie=(n,v)=>{document.cookie=`${n}=${encodeURIComponent(v)}; Path=/; Max-Age=31536000; SameSite=Lax${location.protocol==='https:'?'; Secure':''}`};
const write=(n,action)=>{const r={version:VERSION,necessary:true,analytics:!!n.analytics,advertising:!!n.advertising,updatedAt:new Date().toISOString()};try{localStorage.setItem(KEY,JSON.stringify(r))}catch(e){}cookie('xurvexa_consent_version',VERSION);cookie('xurvexa_consent_analytics',r.analytics?'1':'0');cookie('xurvexa_consent_advertising',r.advertising?'1':'0');fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({version:VERSION,analytics:r.analytics,advertising:r.advertising,action})}).catch(()=>{});root.classList.remove('is-visible');prefs.classList.remove('is-visible');manage.hidden=false;save.hidden=true;window.dispatchEvent(new CustomEvent('xurvexa:consent-changed',{detail:r}));return r};
const current=()=>read()??{version:VERSION,necessary:true,analytics:false,advertising:false,updatedAt:null};
const open=()=>{const c=current();analytics.checked=!!c.analytics;advertising.checked=!!c.advertising;root.classList.add('is-visible')};
window.XurvexaConsent={get:current,has:(c)=>c==='necessary'?true:!!current()[c],open};
root.querySelector('[data-xv-consent-action="accept"]').addEventListener('click',()=>write({analytics:true,advertising:true},'accept_all'));
root.querySelector('[data-xv-consent-action="reject"]').addEventListener('click',()=>write({analytics:false,advertising:false},'reject_optional'));
manage.addEventListener('click',()=>{prefs.classList.add('is-visible');manage.hidden=true;save.hidden=false});
save.addEventListener('click',()=>write({analytics:analytics.checked,advertising:advertising.checked},'save_preferences'));
document.addEventListener('click',e=>{const t=e.target.closest('[data-xurvexa-cookie-settings]');if(t){e.preventDefault();open()}});
if(!read())open();
})();
</script>
