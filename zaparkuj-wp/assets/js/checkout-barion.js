/**
 * Barion checkout integration
 * Заменяет Stripe логику на Barion redirect flow
 */

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
  const I18N = (cfg && cfg.i18n) || {};
  const t = (key, fallback) => (I18N && I18N[key]) ? I18N[key] : (fallback || key);
  const hasI18n = (key) => (I18N && Object.prototype.hasOwnProperty.call(I18N, key));
  function zoneLabelById(zoneId, fallback){
    const id = (zoneId || '').toString().trim();
    if (!id) return fallback || '';
    const key = 'zone_name_' + id.toLowerCase();
    if (hasI18n(key)) return I18N[key];
    return (fallback || id);
  }

  const elForm   = document.getElementById('zp-form');
  if (!elForm) return;

  const elZoneHidden = document.getElementById('band');
  const elMinutes    = document.getElementById('duration');
  const elLat        = document.getElementById('zp-lat');
  const elLng        = document.getElementById('zp-lng');
  const elSpz        = document.getElementById('zp-spz');
  const elEmail      = document.getElementById('zp-email');
  const payBtn       = document.getElementById('zp-pay');
  const elZoneSelect = document.getElementById('zp-zone-select');
  const elZoneTitle  = document.getElementById('zp-zone-title');
  const elZoneMeta   = document.getElementById('zp-zone-meta');
  const elZoneRate   = document.getElementById('zp-zone-rate');
  const elSummary    = document.getElementById('zp-summary');
  const elSumHour    = document.getElementById('zp-sum-hour');
  const elSumDur     = document.getElementById('zp-sum-duration');
  const elSumTotal   = document.getElementById('zp-sum-total');
  const elDurTabs    = document.getElementById('zp-duration-tabs');
  const elCustomTgl  = document.getElementById('zp-custom-time');
  const elCustomWrap = document.getElementById('zp-duration-custom');
  const elDurDisplay = document.getElementById('zp-duration-display');
  let elLang       = document.getElementById('zp-lang');
  let elLangWrap   = document.getElementById('zp-lang-switcher');

  function buildLangSwitcher(container){
    if (!container) return null;
    if (container.querySelector('#zp-lang')) return container.querySelector('#zp-lang');
    const label = document.createElement('label');
    label.setAttribute('for', 'zp-lang');
    label.textContent = t('lang_label', 'Jazyk');
    label.style.margin = '0';
    label.style.fontSize = '12px';
    const select = document.createElement('select');
    select.id = 'zp-lang';
    select.className = 'form-select form-select-sm';
    select.style.width = 'auto';
    select.innerHTML = `
      <option value="sk">${t('lang_sk', 'SK')}</option>
      <option value="en">${t('lang_en', 'EN')}</option>
      <option value="pl">${t('lang_pl', 'PL')}</option>
      <option value="hu">${t('lang_hu', 'HU')}</option>
      <option value="sv">${t('lang_sv', 'SV')}</option>
      <option value="zh">${t('lang_zh', '中文')}</option>
    `;
    container.style.display = 'flex';
    container.style.alignItems = 'center';
    container.style.gap = '8px';
    container.appendChild(label);
    container.appendChild(select);
    return select;
  }

  // Allow placeholder div in theme header
  const headerSlot = document.getElementById('zp-lang-slot');
  if (headerSlot && !elLang) {
    elLang = buildLangSwitcher(headerSlot);
  }

  if (elLangWrap && elLangWrap.dataset && elLangWrap.dataset.fixed === '1') {
    // keep in plugin header
  } else if (elLangWrap && !headerSlot) {
    const desktopRight = document.querySelector('#masthead .site-header-primary-section-right.site-header-section.ast-grid-right-section');
    const mobileRight = document.querySelector('#ast-mobile-header .site-header-primary-section-right.site-header-section.ast-grid-right-section');
    const rightSection = desktopRight || mobileRight;
    if (rightSection) {
      elLangWrap.classList.add('zp-lang-header');
      rightSection.appendChild(elLangWrap);
      if (!document.getElementById('zp-lang-style')) {
        const st = document.createElement('style');
        st.id = 'zp-lang-style';
        st.textContent = '.zp-lang-header{margin-left:auto;padding:6px 12px;display:flex;align-items:center;gap:8px;}@media (max-width: 921px){.zp-lang-header{margin-left:0;}}';
        document.head.appendChild(st);
      }
    } else {
      const mobileTrigger = document.querySelector('#ast-mobile-header .site-header-primary-section-right [data-section="section-header-mobile-trigger"]');
      if (mobileTrigger) {
        mobileTrigger.style.display = 'none';
        elLangWrap.classList.add('zp-lang-header');
        mobileTrigger.parentElement.appendChild(elLangWrap);
        if (!document.getElementById('zp-lang-style')) {
          const st = document.createElement('style');
          st.id = 'zp-lang-style';
          st.textContent = '.zp-lang-header{margin-left:auto;padding:6px 12px;display:flex;align-items:center;gap:8px;}';
          document.head.appendChild(st);
        }
      } else {
        const desktopSlot = document.querySelector('#masthead .site-header-primary-section-right');
        const mobileSlot = document.querySelector('#ast-mobile-header .site-header-primary-section-right');
        const target = desktopSlot || mobileSlot;
        if (target) {
          elLangWrap.classList.add('zp-lang-header');
          target.appendChild(elLangWrap);
          if (!document.getElementById('zp-lang-style')) {
            const st = document.createElement('style');
            st.id = 'zp-lang-style';
            st.textContent = '.zp-lang-header{margin-left:auto;padding:6px 12px;display:flex;align-items:center;gap:8px;}';
            document.head.appendChild(st);
          }
        } else {
          document.body.insertBefore(elLangWrap, document.body.firstChild);
        }
      }
    }
  }

  if (elLang) {
    elLang.value = (cfg && cfg.lang) ? cfg.lang : 'sk';
    elLang.addEventListener('change', () => {
      const params = new URLSearchParams(window.location.search);
      params.set('lang', elLang.value);
      const qs = params.toString();
      window.location.href = window.location.pathname + (qs ? ('?' + qs) : '') + window.location.hash;
    });
  }

  function getZoneId(){ return (elZoneHidden.value || '').toUpperCase(); }
  function getZonesArr(){
    return (cfg.tariffs && cfg.tariffs.zones) || [];
  }
  function getZoneCfg(){
    const zones = getZonesArr();
    return zones.find(z => (z.id || '').toUpperCase() === getZoneId());
  }

  function tpl(str, vars){
    return (str || '').replace(/\{\{(\w+)\}\}/g, function(_, k){
      return (vars && Object.prototype.hasOwnProperty.call(vars, k)) ? String(vars[k]) : '';
    });
  }

  function formatDuration(minutes){
    const m = parseInt(minutes, 10);
    if (!m || m < 0) return '—';
    if (m === 30) return t('duration_30', '30 min');
    if (m === 60) return t('duration_60', '1 hodina');
    if (m === 120) return t('duration_120', '2 hodiny');
    if (m === 240) return t('duration_240', '4 hodiny');
    if (m === 480) return t('duration_480', '8 hodín');
    if (m === 1440) return t('duration_1440', '24 hodín');
    if (m % 60 === 0) return tpl(t('duration_hours', '{{hours}} hodín'), { hours: (m/60) });
    return tpl(t('duration_minutes', '{{minutes}} min'), { minutes: m });
  }

  function zoneRatePerHour(z){
    if (!z) return null;
    if (typeof z.base_30 === 'number') return z.base_30 * 2;
    if (typeof z.rate_per_hour === 'number') return z.rate_per_hour;
    if (typeof z.rate_per_min === 'number') return z.rate_per_min * 60;
    return null;
  }

  function calcPriceCents(z, minutes){
    const m = parseInt(minutes, 10);
    if (!z || !m) return null;

    let cents = 0;
    if (typeof z.base_30 === 'number') {
      const base = z.base_30;
      const cap = (typeof z.daily_cap === 'number' && z.daily_cap >= 0) ? z.daily_cap : null;
      const fullDays = Math.floor(m / 1440);
      const remMin   = m % 1440;
      const blocks   = Math.ceil(remMin / 30);
      let partDay    = blocks * base;
      if (cap !== null) partDay = Math.min(partDay, cap);
      let total = fullDays * (cap !== null ? cap : (48 * base));
      total += partDay;
      cents = Math.round(total * 100);
    } else {
      const step = Math.max(1, z.billing_increment || 15);
      const minc = Math.max(0, z.min_charge_minutes || 0);
      const mm   = Math.max(minc, Math.ceil(m/step)*step);
      const rate = z.rate_per_min || (z.rate_per_hour ? (z.rate_per_hour/60) : 0);
      cents = Math.round(mm * rate * 100);
    }
    return cents;
  }

  function updateZoneUI(){
    const z = getZoneCfg();
    const zoneId = getZoneId();
    if (elZoneTitle) elZoneTitle.textContent = z ? (z.label || z.id || zoneId) : '—';
    if (elZoneMeta) elZoneMeta.textContent = (t('zone_label_default', 'Zóna') + ': ' + (zoneId || '—'));
    if (elZoneRate) {
      const r = zoneRatePerHour(z);
      elZoneRate.textContent = (r !== null && isFinite(r))
        ? tpl(t('rate_per_hour', '{{price}} €/hod'), { price: r.toFixed(2) })
        : '—';
    }
  }

  function syncDurationUI(){
    const m = parseInt(elMinutes.value, 10) || 0;
    if (elDurDisplay) elDurDisplay.textContent = formatDuration(m);
    if (!elDurTabs) return;
    const btns = elDurTabs.querySelectorAll('[data-min]');
    btns.forEach(b => {
      const val = parseInt(b.getAttribute('data-min') || '0', 10);
      if (val === m) b.classList.add('is-active');
      else b.classList.remove('is-active');
    });
  }

  function updateSummaryUI(cents){
    const z = getZoneCfg();
    const zoneId = getZoneId();
    const minutes = parseInt(elMinutes.value, 10) || 0;
    if (!elSummary) return;

    if (!z || !zoneId || !minutes || cents === null) {
      elSummary.style.display = 'none';
      return;
    }
    elSummary.style.display = 'flex';

    const r = zoneRatePerHour(z);
    if (elSumHour) elSumHour.textContent = (r !== null && isFinite(r)) ? r.toFixed(2) + ' €' : '—';
    if (elSumDur) elSumDur.textContent = formatDuration(minutes);
    if (elSumTotal) elSumTotal.textContent = (cents/100).toFixed(2) + ' €';
  }

  function updatePayUI(cents){
    if (!payBtn) return;
    const zoneId = getZoneId();
    const minutes = parseInt(elMinutes.value, 10) || 0;
    const ok = !!zoneId && !!minutes && cents !== null && cents > 0;
    payBtn.disabled = !ok;
    const priceStr = ok ? (cents/100).toFixed(2) : '0.00';
    payBtn.textContent = tpl(t('pay_button_price', 'Zaplatiť {{price}} €'), { price: priceStr });
  }

  function recalcAll(){
    updateZoneUI();
    syncDurationUI();
    const z = getZoneCfg();
    const cents = calcPriceCents(z, elMinutes.value);
    updateSummaryUI(cents);
    updatePayUI(cents);
    return cents;
  }

  // Recalc on duration changes
  if (elMinutes) {
    elMinutes.addEventListener('input', recalcAll);
    elMinutes.addEventListener('change', recalcAll);
  }

  // Sync when zone changes (geo/manual select)
  document.addEventListener('zp:zoneResolved', recalcAll);
  if (elZoneSelect) {
    elZoneSelect.addEventListener('change', () => {
      // keep in sync even if geo.js didn't run yet
      if (elZoneHidden) elZoneHidden.value = (elZoneSelect.value || '').toUpperCase();
      recalcAll();
    });
  }

  // Duration tabs (30/60/120/240)
  if (elDurTabs) {
    elDurTabs.addEventListener('click', (e) => {
      const btn = e.target && e.target.closest ? e.target.closest('[data-min]') : null;
      if (!btn) return;
      const m = parseInt(btn.getAttribute('data-min') || '0', 10);
      if (!m || !elMinutes) return;
      elMinutes.value = String(m);
      elMinutes.dispatchEvent(new Event('input', { bubbles: true }));
      elMinutes.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  // Custom time toggle (+/-)
  let customOn = false;
  function setCustom(on){
    customOn = !!on;
    if (elCustomWrap) elCustomWrap.style.display = customOn ? 'block' : 'none';
    if (elDurTabs) elDurTabs.style.display = customOn ? 'none' : 'grid';
  }
  if (elCustomTgl) {
    elCustomTgl.addEventListener('click', () => {
      setCustom(!customOn);
      syncDurationUI();
    });
  }
  if (elCustomWrap) {
    elCustomWrap.addEventListener('click', (e) => {
      const btn = e.target && e.target.closest ? e.target.closest('[data-add]') : null;
      if (!btn || !elMinutes) return;
      const delta = parseInt(btn.getAttribute('data-add') || '0', 10);
      if (!delta) return;
      const cur = parseInt(elMinutes.value, 10) || 0;
      const next = Math.max(30, cur + delta);
      elMinutes.value = String(next);
      elMinutes.dispatchEvent(new Event('input', { bubbles: true }));
      elMinutes.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }
  setCustom(false);

  /**
   * Создание платежа в Barion
   */
  async function createBarionPayment() {
    const body = {
      spz: (elSpz.value || '').trim().toUpperCase(),
      email: (elEmail.value || '').trim(),
      zone_id: getZoneId(),
      minutes: parseInt(elMinutes.value, 10),
      lat: elLat.value ? parseFloat(elLat.value) : null,
      lng: elLng.value ? parseFloat(elLng.value) : null,
      amount_cents: recalcAll(),
      lang: (cfg && cfg.lang) ? cfg.lang : 'sk'
    };

    // Валидация
    if (!body.spz || body.spz.length < 2) {
      throw new Error(t('err_spz', 'Zadaj platnú ŠPZ'));
    }
    if (!body.email || !body.email.includes('@')) {
      throw new Error(t('err_email', 'Zadaj platný email'));
    }
    if (!body.zone_id) {
      throw new Error(t('err_zone', 'Zóna nebola určená'));
    }
    if (!body.minutes || body.minutes < 1) {
      throw new Error(t('err_minutes', 'Vyber trvanie parkovania'));
    }
    if (!body.amount_cents || body.amount_cents < 1) {
      throw new Error(t('err_price', 'Nepodarilo sa vypočítať cenu'));
    }

    // Volanie REST API
    const res = await fetch((cfg.rest && cfg.rest.barion_prepare) || '/wp-json/zaparkuj/v1/barion-prepare', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(body)
    });

    if (!res.ok) {
      const txt = await res.text();
      throw new Error(txt || t('err_create_payment', 'Chyba pri vytváraní platby'));
    }

    return await res.json();
  }

  /**
   * Обработка нажатия кнопки оплаты
   */
  if (payBtn) {
    payBtn.addEventListener('click', async (e) => {
      e.preventDefault();

      // Отключить кнопку
      payBtn.disabled = true;
      payBtn.textContent = t('preparing_payment', '⏳ Pripravujem platbu...');

      try {
        const result = await createBarionPayment();

        if (!result.success) {
          throw new Error(result.error || t('err_create_payment', 'Chyba pri vytváraní platby'));
        }

        // Редирект на Barion платёжную страницу
        if (result.redirectUrl) {
          payBtn.textContent = t('redirecting', '🔄 Presmerovávam na Barion...');
          window.location.href = result.redirectUrl;
        } else {
          throw new Error(t('err_missing_redirect', 'Chýba redirect URL od Barion'));
        }

      } catch (err) {
        console.error('Barion payment error:', err);
        alert(err.message || t('err_generic', 'Nastala chyba. Skús to znova.'));
        recalcAll();
      }
    });
  }

  // Проверка статуса после возврата с Barion
  const urlParams = new URLSearchParams(window.location.search);
  const paymentStatus = urlParams.get('zp_payment');

  const appRoot = document.getElementById('zp-app');
  const successScreen = document.getElementById('zp-success-screen');
  const emailModal = document.getElementById('zp-email-modal');
  const emailIframe = document.getElementById('zp-email-iframe');
  const emailClose = document.getElementById('zp-email-close');
  const viewEmailBtn = document.getElementById('zp-view-email');
  const downloadBtn = document.getElementById('zp-download');
  const shareBtn = document.getElementById('zp-share');
  const newParkingBtn = document.getElementById('zp-new-parking');

  function showOverlay(el, on){
    if (!el) return;
    el.style.display = on ? 'block' : 'none';
    el.setAttribute('aria-hidden', on ? 'false' : 'true');
  }

  function cleanParams(keys){
    try {
      const p = new URLSearchParams(window.location.search);
      keys.forEach(k => p.delete(k));
      const qs = p.toString();
      const next = window.location.pathname + (qs ? ('?' + qs) : '') + window.location.hash;
      if (window.history && window.history.replaceState) {
        window.history.replaceState({}, document.title, next);
      }
    } catch(_e) {}
  }

  function fmtDate(mysql){
    if (!mysql) return '';
    const parts = String(mysql).split(' ');
    const d = parts[0] || '';
    const dd = d.split('-');
    if (dd.length !== 3) return d;
    return dd[2] + '.' + dd[1] + '.' + dd[0];
  }
  function fmtTime(mysql){
    if (!mysql) return '';
    const parts = String(mysql).split(' ');
    const t = parts[1] || '';
    return t ? t.slice(0,5) : '';
  }

  function fillSuccess(paymentId){
    const data = (cfg && cfg.payment_success) ? cfg.payment_success : null;
    const txn = (data && (data.orderNumber || data.paymentId)) ? (data.orderNumber || data.paymentId) : (paymentId || '');

    const elMsg = document.getElementById('zp-success-msg');
    const elQr = document.getElementById('zp-qr-id');
    const elTxn = document.getElementById('zp-txn-id');
    const elSpz = document.getElementById('zp-success-spz');
    const elEmail = document.getElementById('zp-success-email');
    const elZone = document.getElementById('zp-success-zone');
    const elZoneId = document.getElementById('zp-success-zone-id');
    const elDate = document.getElementById('zp-success-date');
    const elTime = document.getElementById('zp-success-time');
    const elDur = document.getElementById('zp-success-duration');
    const elTotal = document.getElementById('zp-success-total');
    const elValid = document.getElementById('zp-success-valid');

    if (elQr) elQr.textContent = txn || '';
    if (elTxn) elTxn.textContent = txn || '';

    const email = data && data.email ? String(data.email) : '';
    if (elMsg) {
      elMsg.textContent = email
        ? tpl(t('success_message_email', 'Vaša platba bola spracovaná. Potvrdenie bolo odoslané na {{email}}.'), { email })
        : '';
    }

    if (elSpz) elSpz.textContent = data && data.spz ? String(data.spz) : '';
    if (elEmail) elEmail.textContent = email;
    const zoneId = data && data.zone_id ? String(data.zone_id) : '';
    const zoneLabel = zoneLabelById(zoneId, data && data.zone_label ? String(data.zone_label) : '');
    if (elZone) elZone.textContent = zoneLabel;
    if (elZoneId) elZoneId.textContent = zoneId ? (t('zone_label_default', 'Zóna') + ' ' + zoneId) : '';

    const start = data && data.started_at ? String(data.started_at) : (data && data.paid_at ? String(data.paid_at) : '');
    const end = data && data.expires_at ? String(data.expires_at) : '';
    if (elDate) elDate.textContent = fmtDate(start);
    if (elTime) {
      const st = fmtTime(start);
      const et = fmtTime(end);
      elTime.textContent = st && et ? (st + ' - ' + et) : (st || '');
    }

    const mins = data && data.minutes ? parseInt(data.minutes, 10) : 0;
    if (elDur) elDur.textContent = mins ? formatDuration(mins) : '';

    const cents = data && data.amount_cents ? parseInt(data.amount_cents, 10) : 0;
    if (elTotal) elTotal.textContent = cents ? (cents/100).toFixed(2) + ' €' : '';

    if (elValid && end) {
      const time = fmtTime(end);
      const date = fmtDate(end);
      elValid.textContent = tpl(t('parking_valid_until', 'Parkovanie platné do {{time}}, {{date}}'), { time, date });
    }
  }

  function openEmailPreview(){
    if (!emailModal || !emailIframe) return;
    const html = (cfg && cfg.email_preview_html) ? String(cfg.email_preview_html) : '';
    if (!html) { alert(t('err_generic', 'Nastala chyba. Skús to znova.')); return; }
    emailIframe.srcdoc = html;
    showOverlay(emailModal, true);
  }

  if (emailClose) emailClose.addEventListener('click', () => showOverlay(emailModal, false));
  if (emailModal) {
    emailModal.addEventListener('click', (e) => {
      if (e && e.target === emailModal) showOverlay(emailModal, false);
    });
  }
  if (viewEmailBtn) viewEmailBtn.addEventListener('click', openEmailPreview);

  if (downloadBtn) downloadBtn.addEventListener('click', () => {
    // Minimal: print the email confirmation if available, otherwise print page.
    if (emailIframe && cfg && cfg.email_preview_html) {
      openEmailPreview();
      emailIframe.onload = () => {
        try { emailIframe.contentWindow && emailIframe.contentWindow.print(); } catch(_e) { window.print(); }
      };
    } else {
      window.print();
    }
  });

  if (shareBtn) shareBtn.addEventListener('click', async () => {
    const data = (cfg && cfg.payment_success) ? cfg.payment_success : null;
    const text = data
      ? (String(data.spz || '') + ' – ' + String(data.zone_label || data.zone_id || ''))
      : 'parkovne.sk';
    if (navigator.share) {
      try { await navigator.share({ title: 'parkovne.sk', text }); } catch(_e) {}
      return;
    }
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
        alert(t('copied', 'Skopírované'));
      }
    } catch(_e) {}
  });

  if (newParkingBtn) newParkingBtn.addEventListener('click', () => {
    cleanParams(['zp_payment','pid']);
    showOverlay(successScreen, false);
    if (appRoot) appRoot.style.display = '';
    // reset UI state
    if (elZoneHidden) elZoneHidden.value = '';
    if (elZoneSelect) elZoneSelect.value = '';
    recalcAll();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  if (paymentStatus === 'success') {
    if (appRoot) appRoot.style.display = 'none';
    showOverlay(successScreen, true);
    fillSuccess(urlParams.get('pid'));
    // Keep URL clean for subsequent reloads, but preserve other params like lang.
    cleanParams(['zp_payment','pid']);
  } else if (paymentStatus === 'failed' || paymentStatus === 'error') {
    const banner = document.getElementById('zone-result');
    if (banner) {
      banner.className = 'zp-banner is-bad';
      banner.textContent = paymentStatus === 'failed'
        ? t('status_failed', 'Platba nebola dokončená. Skús to znova.')
        : t('status_error', 'Nastala chyba pri spracovaní platby.');
    }
    cleanParams(['zp_payment','pid']);
  }

  // Initial UI sync
  recalcAll();
});
