
document.addEventListener('DOMContentLoaded', () => {
  function getCfg(){
    try {
      const el = document.getElementById('zp-config');
      if (!el) return null;
      const cfg = JSON.parse(el.textContent || el.innerText || '{}');
      window.ZP = window.ZP || cfg;
      return cfg;
    } catch(e){ console.warn('ZP config parse failed', e); return null; }
  }
  const cfg = getCfg() || {};
  const urlp = new URLSearchParams(location.search);
  const isStub = urlp.get('stub') === '1' || (window.ZPTest && typeof ZPTest.isStub==='function' && ZPTest.isStub());

  const elForm   = document.getElementById('zp-form');
  if (!elForm) return;

  const elZoneHidden = document.getElementById('band');
  const elMinutes    = document.getElementById('duration');
  const elLat        = document.getElementById('zp-lat');
  const elLng        = document.getElementById('zp-lng');
  const elSpz        = document.getElementById('zp-spz');
  const elEmail      = document.getElementById('zp-email');
  const payBtn       = document.getElementById('zp-pay');
  const elPrice      = document.getElementById('zp-price');

  let stripe, elements, clientSecret;

  function getZoneId(){ return (elZoneHidden.value || '').toUpperCase(); }
  function getZonesArr(){
    return (cfg.tariffs && cfg.tariffs.zones) || [];
  }
  function getZoneCfg(){
    const zones = getZonesArr();
    return zones.find(z => (z.id || '').toUpperCase() === getZoneId());
  }

  function calcPrice(){
    const z = getZoneCfg();
    const minutes = parseInt(elMinutes.value, 10);
    if (!z || !minutes) { if (elPrice) elPrice.textContent = '—'; return null; }

    let cents = 0;
    if (typeof z.base_30 === 'number') {
      const base = z.base_30;
      const cap = (typeof z.daily_cap === 'number' && z.daily_cap >= 0) ? z.daily_cap : null;
      const fullDays = Math.floor(minutes / 1440);
      const remMin   = minutes % 1440;
      const blocks   = Math.ceil(remMin / 30);
      let partDay    = blocks * base;
      if (cap !== null) partDay = Math.min(partDay, cap);
      let total = fullDays * (cap !== null ? cap : (48 * base));
      total += partDay;
      cents = Math.round(total * 100);
    } else {
      const step = Math.max(1, z.billing_increment || 15);
      const minc = Math.max(0, z.min_charge_minutes || 0);
      const m    = Math.max(minc, Math.ceil(minutes/step)*step);
      const rate = z.rate_per_min || (z.rate_per_hour ? (z.rate_per_hour/60) : 0);
      cents = Math.round(m * rate * 100);
    }

    if (elPrice) elPrice.textContent = (cents/100).toFixed(2) + ' €';
    return cents;
  }

  async function createSession(){
    const body = {
      spz: (elSpz.value || '').trim().toUpperCase(),
      email: (elEmail.value || '').trim(),
      zone_id: getZoneId(),
      minutes: parseInt(elMinutes.value,10),
      lat: elLat.value ? parseFloat(elLat.value) : null,
      lng: elLng.value ? parseFloat(elLng.value) : null,
      client_token: (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()))
    };
    const res = await fetch((cfg.rest && cfg.rest.session) || '/wp-json/zaparkuj/v1/session', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(body)
    });
    if(!res.ok) throw new Error(await res.text() || 'Chyba pri vytváraní platby');
    return await res.json();
  }

  async function loadStripeIfNeeded() {
    if (window.Stripe) return;
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://js.stripe.com/v3/';
      s.async = true;
      s.onload = resolve;
      s.onerror = () => reject(new Error('Stripe JS failed to load'));
      document.head.appendChild(s);
    });
  }

  async function initStripe(client_secret){
    await loadStripeIfNeeded();
    if (!window.Stripe) throw new Error('Stripe JS unavailable');
    if (!cfg || !cfg.publishableKey) throw new Error('Chýba Stripe publishable key');
    stripe   = Stripe(cfg.publishableKey);
    elements = stripe.elements({ clientSecret: client_secret });
    const paymentElement = elements.create('payment');
    let pe = document.getElementById('zp-payment-element');
    if(!pe){
      pe = document.createElement('div');
      pe.id = 'zp-payment-element';
      pe.style.marginTop = '1rem';
      document.getElementById('zp-form').appendChild(pe);
    }
    paymentElement.mount('#zp-payment-element');
  }

  elMinutes.addEventListener('change', calcPrice);
  document.addEventListener('zp:zoneResolved', calcPrice);
  calcPrice();

  elForm.addEventListener('submit', async (e)=>{
    // STUB: emulate payment without Stripe if ?stub=1
    if (isStub) {
      e.preventDefault(); e.stopImmediatePropagation();
      try{
        payBtn.disabled = true;
        const cents = calcPrice();
        if(!cents) throw new Error('Nie je možné vypočítať cenu – neznáma zóna alebo minúty.');
        const res = await fetch((cfg.rest && cfg.rest.stub_mail) || '/wp-json/zaparkuj/v1/stub-mail', {
          method: 'POST', headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ email: (elEmail.value||'').trim(), spz:(elSpz.value||'').trim().toUpperCase(), zone_id:getZoneId(), minutes: parseInt(elMinutes.value,10), lat: parseFloat(elLat.value||0)||null, lng: parseFloat(elLng.value||0)||null })
        });
        let data = null; try{ data = await res.json(); }catch(_){}
        if(!res.ok){ throw new Error((data && (data.message||data.code)) || 'Stub-mail error'); }
        alert('✅ Test: platba simulovaná. Potvrdenie (stub) odoslané.');
        document.dispatchEvent(new CustomEvent('zp:paymentSucceeded', { detail: { stub:true, amount_cents: cents } }));
      }catch(err){ alert(err.message || 'Neznáma chyba (stub)'); console.error(err); }
      finally{ payBtn.disabled = false; }
      return;
    }

    e.preventDefault();
    payBtn.disabled = true;
    try{
      const cents = calcPrice();
      if(!cents) throw new Error('Nie je možné vypočítať cenu – neznáma zóna alebo minúty.');

      const s = await createSession();
      clientSecret = s.client_secret || s.clientSecret;
      if(!clientSecret) throw new Error('Chýba client_secret v odpovedi servera');

      await initStripe(clientSecret);
      const { error } = await stripe.confirmPayment({ elements, redirect: 'if_required' });
      if (error) throw new Error(error.message || 'Platba zlyhala');
      alert('Platba spracovaná. Skontrolujte e-mail s potvrdením.');
    }catch(err){
      console.error(err);
      alert(err.message || 'Neznáma chyba');
      payBtn.disabled = false;
    }
  });
});
