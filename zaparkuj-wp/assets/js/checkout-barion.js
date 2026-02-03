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

  // Пересчёт цены при изменении длительности
  if (elMinutes) {
    elMinutes.addEventListener('input', calcPrice);
    elMinutes.addEventListener('change', calcPrice);
  }

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
      amount_cents: calcPrice(),
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
      const originalText = payBtn.textContent;
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
        payBtn.disabled = false;
        payBtn.textContent = originalText;
      }
    });
  }

  // Проверка статуса после возврата с Barion
  const urlParams = new URLSearchParams(window.location.search);
  const paymentStatus = urlParams.get('zp_payment');

  if (paymentStatus === 'success') {
    const paymentId = urlParams.get('pid');
    showSuccessMessage(paymentId);
  } else if (paymentStatus === 'failed') {
    showErrorMessage(t('status_failed', 'Platba nebola dokončená. Skús to znova.'));
  } else if (paymentStatus === 'error') {
    showErrorMessage(t('status_error', 'Nastala chyba pri spracovaní platby.'));
  }

  function showSuccessMessage(paymentId) {
    const container = document.querySelector('.container');
    if (!container) return;

    const msg = document.createElement('div');
    msg.className = 'alert alert-success alert-dismissible fade show';
    msg.innerHTML = `
      <h4 class="alert-heading">${t('success_heading', '✅ Platba úspešná!')}</h4>
      <p>${t('success_body', 'Parkovanie bolo aktivované. Potvrdenie sme zaslali na tvoj email.')}</p>
      ${paymentId ? `<small class="text-muted">${t('payment_id_label', 'ID platby')}: ${paymentId}</small>` : ''}
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    container.insertBefore(msg, container.firstChild);

    // Vyčistiť URL
    if (window.history && window.history.replaceState) {
      window.history.replaceState({}, document.title, window.location.pathname);
    }
  }

  function showErrorMessage(text) {
    const container = document.querySelector('.container');
    if (!container) return;

    const msg = document.createElement('div');
    msg.className = 'alert alert-danger alert-dismissible fade show';
    msg.innerHTML = `
      <strong>⚠️ ${text}</strong>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    container.insertBefore(msg, container.firstChild);

    // Vyčistiť URL
    if (window.history && window.history.replaceState) {
      window.history.replaceState({}, document.title, window.location.pathname);
    }
  }

  // Initial price calculation
  calcPrice();
});
