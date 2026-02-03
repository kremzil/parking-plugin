
(function(){
  function getCfg(){
    try {
      var el = document.getElementById('zp-config');
      if (!el) return {};
      var cfg = JSON.parse(el.textContent || el.innerText || '{}');
      window.ZP = window.ZP || cfg;
      return cfg || {};
    } catch(e){ console.warn('ZP config parse failed', e); return {}; }
  }
  const CFG = getCfg();
  const I18N = (CFG && CFG.i18n) || {};
  const t = (key, fallback) => (I18N && I18N[key]) ? I18N[key] : (fallback || key);
  function tpl(str, vars){
    return (str || '').replace(/\{\{(\w+)\}\}/g, function(_, k){
      return (vars && Object.prototype.hasOwnProperty.call(vars, k)) ? vars[k] : '';
    });
  }
  const ENABLE_CIRCLES = (CFG && CFG.enable_circles === true) ? true : false; // default disabled
  const TOL_M = Math.max(0, parseFloat(CFG.geojson_tolerance_m || 25)); // meters
  const IS_OSM = (CFG && CFG.map_provider === 'osm');
  const DEFAULT_CENTER = (CFG && CFG.default_center && typeof CFG.default_center.lat==='number' && typeof CFG.default_center.lng==='number')
    ? {lat: CFG.default_center.lat, lng: CFG.default_center.lng}
    : {lat: 48.7205, lng: 21.2575}; // mock1 fallback

  // ----- geometry utils -----
  const toRad = (x) => x * Math.PI / 180;
  function metersPerDeg(lat){
    const latRad = toRad(lat);
    const m_per_deg_lat = 111132.954 - 559.822 * Math.cos(2*latRad) + 1.175 * Math.cos(4*latRad);
    const m_per_deg_lon = 111132.954 * Math.cos(latRad);
    return {mx: m_per_deg_lon, my: m_per_deg_lat};
  }
  function pip(point, ring){
    let x = point[0], y = point[1], inside = false;
    for (let i=0, j=ring.length-1; i<ring.length; j=i++) {
      const [xi,yi] = ring[i];
      const [xj,yj] = ring[j];
      const intersect = ((yi>y)!==(yj>y)) && (x < (xj-xi)*(y-yi)/(yj-yi) + xi);
      if (intersect) inside = !inside;
    }
    return inside;
  }
  function distPointSegMeters(p, a, b){
    const latRef = (p[1] + a[1] + b[1]) / 3;
    const {mx, my} = metersPerDeg(latRef);
    const px = p[0]*mx, py = p[1]*my;
    const ax = a[0]*mx, ay = a[1]*my;
    const bx = b[0]*mx, by = b[1]*my;
    const vx = bx-ax, vy=by-ay;
    const wx = px-ax, wy=py-ay;
    const c1 = vx*wx + vy*wy;
    const c2 = vx*vx + vy*vy;
    let t = c2 ? (c1/c2) : 0;
    if (t<0) t=0; else if (t>1) t=1;
    const projx = ax + t*vx, projy = ay + t*vy;
    const dx = px - projx, dy = py - projy;
    return Math.sqrt(dx*dx + dy*dy);
  }
  function minDistToRingMeters(pt, ring){
    let minD = Infinity;
    for (let i=0, j=ring.length-1; i<ring.length; j=i++){
      const a = ring[j], b = ring[i];
      const d = distPointSegMeters(pt, a, b);
      if (d < minD) minD = d;
    }
    return minD;
  }
  function insideOrNearPolygon(pt, coords, tol_m){
    if (!coords || !coords.length) return false;
    const outer = coords[0];
    const insideOuter = pip(pt, outer);
    if (insideOuter){
      for (let h=1; h<coords.length; h++){
        if (pip(pt, coords[h])) return false;
      }
      return true;
    }
    if (tol_m > 0){
      const d = minDistToRingMeters(pt, outer);
      if (d <= tol_m) return true;
    }
    return false;
  }

  async function resolveBandGeoJSON(lat, lng){
    const url = (CFG && (CFG.geojson_url || (CFG.geojson && CFG.geojson.url))) || null;
    if (!url) return null;
    try{
      const res = await fetch(url, { cache: 'no-cache' });
      if(!res.ok) throw new Error('geojson fetch failed');
      const gj = await res.json();
      window.ZP = window.ZP || {}; window.ZP.geojsonData = gj;
      const pt = [lng, lat];
      const features = (gj && gj.features) || [];
      for (const f of features) {
        if (!f || !f.geometry) continue;
        const g = f.geometry, props = f.properties || {};
        if (g.type === 'Polygon') {
          if (insideOrNearPolygon(pt, g.coordinates, TOL_M)) {
            return { band: (props.id||props.zone_id||props.name||'Z').toUpperCase(), label: props.label || props.id || t('zone_label_default', 'Zóna') };
          }
        } else if (g.type === 'MultiPolygon') {
          for (const poly of g.coordinates) {
            if (insideOrNearPolygon(pt, poly, TOL_M)) {
              return { band: (props.id||props.zone_id||props.name||'Z').toUpperCase(), label: props.label || props.id || t('zone_label_default', 'Zóna') };
            }
          }
        }
      }
    }catch(err){
      console.warn('GeoJSON resolve failed:', err);
    }
    return null;
  }

  // circles fallback (disabled by default, opt-in in settings)
  const toRad2 = toRad;
  function haversine(lat1, lon1, lat2, lon2) {
    const R = 6371e3, dLat = toRad2(lat2 - lat1), dLon = toRad2(lon1 - lon2);
    const a = Math.sin(dLat/2)**2 + Math.cos(toRad2(lat1)) * Math.cos(toRad2(lat2)) * Math.sin(dLon/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }
  function resolveBandCircles(lat, lng) {
    try {
      if (typeof POCKETS_N !== 'undefined' && Array.isArray(POCKETS_N)) {
        for (const z of POCKETS_N)
          if (haversine(lat, lng, z.lat, z.lng) <= z.radiusM) return { band: 'N', label: z.label || t('zone_label_special_n', 'N (osobitné)') };
      }
      if (typeof A_META !== 'undefined' && Array.isArray(A_META)) {
        let inA = false;
        for (const z of A_META) {
          if (haversine(lat, lng, z.lat, z.lng) <= z.radiusM) { inA = true; break; }
        }
        if (inA) {
          if (typeof POCKETS_A1 !== 'undefined' && Array.isArray(POCKETS_A1)) {
            for (const p of POCKETS_A1)
              if (haversine(lat, lng, p.lat, p.lng) <= p.radiusM) return { band: 'A1', label: p.label || t('zone_label_loc_a1', 'Lokalita A – A1') };
          }
          return { band: 'A2', label: t('zone_label_loc_a2', 'Lokalita A – A2') };
        }
      }
      if (typeof B_META !== 'undefined' && Array.isArray(B_META)) {
        let inB = false;
        for (const z of B_META) {
          if (haversine(lat, lng, z.lat, z.lng) <= z.radiusM) { inB = true; break; }
        }
        if (inB) {
          if (typeof POCKETS_BN !== 'undefined' && Array.isArray(POCKETS_BN)) {
            for (const p of POCKETS_BN)
              if (haversine(lat, lng, p.lat, p.lng) <= p.radiusM) return { band: 'BN', label: p.label || t('zone_label_loc_bn', 'Lokalita B – BN') };
          }
          return { band: 'B', label: t('zone_label_loc_b', 'Lokalita B') };
        }
      }
    } catch(_){}
    return null;
  }

  // ---- map rendering ----
  let _map = null, _marker = null, _zonesLayer = null, _hiLayer = null, _gjData = null;
  let _pickActive = false;
  function setHelpLL(lat, lng, source){
    const el = document.getElementById('zp-ll-help');
    if (!el) return;
    const prefix = source === 'manual' ? t('help_manual_prefix', 'Ručný výber: ') : t('help_gps_prefix', 'Poloha: ');
    el.textContent = (lat && lng) ? (prefix + lat.toFixed(6) + ', ' + lng.toFixed(6)) : '';
  }
  function ensureOSMMap(lat,lng){
    if (!IS_OSM) return;
    const el = document.getElementById('map-frame');
    if (!el) return;
    if (!_map){
      _map = L.map('map-frame').setView([lat,lng], 18);
      _map.on('click', function(e){ if(_pickActive){ _pickActive=false; afterPosition(+e.latlng.lat.toFixed(6), +e.latlng.lng.toFixed(6)); setHelpLL(e.latlng.lat, e.latlng.lng, 'manual'); const el = document.getElementById('map-frame'); if (el) el.style.cursor=''; } });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
      }).addTo(_map);
      // Geocoder (Leaflet Control Geocoder via Nominatim)
      try {
        if (L.Control && L.Control.geocoder) {
          L.Control.geocoder({ defaultMarkGeocode:false, placeholder:t('geocoder_placeholder', 'Hľadať adresu…') })
            .on('markgeocode', function(e){
              var ll = e.geocode.center;
              _pickActive = false;
              afterPosition(+ll.lat.toFixed(6), +ll.lng.toFixed(6));
              setHelpLL(ll.lat, ll.lng, 'manual');
              var el = document.getElementById('map-frame'); if (el) el.style.cursor='';
            })
            .addTo(_map);
        }
      } catch(e){ console.warn('geocoder init failed', e); }
      const url = (CFG && (CFG.geojson_url || (CFG.geojson && CFG.geojson.url))) || null;
      if (url){
        fetch(url).then(r=>r.json()).then(g=>{
          _gjData = g;
          _zonesLayer = L.geoJSON(g, {
            style: { color: '#2563eb', weight: 2, fillOpacity: 0.08 }
          }).addTo(_map);
        }).catch(console.warn);
      }
    }
    if (!_marker) _marker = L.marker([lat,lng]).addTo(_map); else _marker.setLatLng([lat,lng]);
    _map.setView([lat,lng], _map.getZoom());
  }
  function highlightZoneById(zoneId){
    if (!IS_OSM || !_gjData) return;
    if (_hiLayer){ _hiLayer.remove(); _hiLayer = null; }
    const feats = (_gjData.features||[]).filter(f => {
      const pid = (f.properties && (f.properties.id||f.properties.zone_id||f.properties.name)||'')+'';
      return pid.toUpperCase() === (zoneId||'').toUpperCase();
    });
    if (feats.length){
      _hiLayer = L.geoJSON({type:'FeatureCollection', features:feats}, {
        style: { color: '#d97706', weight: 4, fillOpacity: 0.15 }
      }).addTo(_map);
      try{ _map.fitBounds(_hiLayer.getBounds(), {maxZoom: 18, padding:[10,10]}); }catch(_){}
    }
  }

  function renderMap(lat, lng) {
    const el = document.getElementById('map-frame');
    if (!el) return;
    if (IS_OSM) { ensureOSMMap(lat, lng); return; }
    el.innerHTML =
      `<iframe width="100%" height="100%" style="border:0" loading="lazy" allowfullscreen
        src="https://maps.google.com/maps?q=${lat},${lng}&z=18&output=embed"></iframe>`;
  }

  function showGeoModal(msg){
    const m = document.getElementById('geoModal');
    const t = document.getElementById('geo-wait-msg');
    if (!m) return;
    if (t && msg) { t.textContent = msg; t.style.display = 'block'; }
    m.classList.add('show');
    m.style.display = 'block';
    m.removeAttribute('aria-hidden');
  }
  function hideGeoModal(){
    const m = document.getElementById('geoModal');
    if (!m) return;
    m.classList.remove('show');
    m.style.display = 'none';
    m.setAttribute('aria-hidden','true');
  }
  function setGeoWaitMsg(msg){
    const t = document.getElementById('geo-wait-msg');
    if (t) { t.textContent = msg; t.style.display = 'block'; }
  }

  async function afterPosition(lat, lng) {
    const latEl = document.getElementById('zp-lat');
    const lngEl = document.getElementById('zp-lng');
    if (latEl) latEl.value = lat;
    if (lngEl) lngEl.value = lng;
    renderMap(lat, lng);

    const zoneEl = document.getElementById('zone-result');
    let z = await resolveBandGeoJSON(parseFloat(lat), parseFloat(lng));
    if (!z && ENABLE_CIRCLES) {
      z = resolveBandCircles(parseFloat(lat), parseFloat(lng));
    }

    if (z) {
      document.getElementById('band').value = (z.band || '').toUpperCase();
      if (zoneEl) {
        zoneEl.className = 'alert alert-success';
        zoneEl.innerHTML = tpl(t('zone_result_success_html', 'Zóna: <b>{{label}}</b> (tarifa {{band}})'), { label: z.label, band: z.band });
      }
      if (IS_OSM) highlightZoneById(z.band);
    } else {
      document.getElementById('band').value = '';
      if (zoneEl) {
        zoneEl.className = 'alert alert-danger';
        zoneEl.textContent = t('zone_result_outside', '❌ Poloha mimo zón.');
      }
    }

    document.dispatchEvent(new CustomEvent('zp:zoneResolved', { detail: { band: z ? z.band : null } }));
  }

  function hasMock(){
    return (window.ZPTest && typeof window.ZPTest.isMock === 'function')
      ? window.ZPTest.isMock()
      : new URLSearchParams(location.search).get('mock') === '1';
  }

  function initGeo() {
    const zoneEl = document.getElementById('zone-result');
    // Показать карту сразу (по центру мок1), чтобы UI не был пустой
    try { if (IS_OSM) { ensureOSMMap(DEFAULT_CENTER.lat, DEFAULT_CENTER.lng); } } catch(_){}

    // модалка — только по необходимости; изначально скрываем "ожидание"
    const waitMsg = document.getElementById('geo-wait-msg');
    if (waitMsg) waitMsg.style.display = 'none';

      // --- авто-закрытие модалки при разрешении гео ---
    let _permStatus = null;
    let _watchStarted = false;
    let _userPickedManually = false;
    let _geoInFlight = false;

    function startPermissionWatch() {
      if (_watchStarted) return;   // <<< защита от повторной подписки
      _watchStarted = true;

      if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'geolocation' }).then(p => {
          _permStatus = p;
          p.onchange = () => {
            // если пользователь включил гео — пробуем снова,
            // но не мешаем, если он уже выбрал точку вручную
            if (p.state !== 'denied' && !_userPickedManually) {
              startGeo(); // успех сам закроет модалку
            }
          };
        }).catch(()=>{});
      }

      const onVis = () => {
        if (!document.hidden && !_userPickedManually) {
          startGeo();
        }
      };
      document.addEventListener('visibilitychange', onVis);
    }

    // Кнопки модалки
    const btnPickInModal = document.getElementById('zp-modal-pick');
    const btnPick = document.getElementById('zp-pick'); // под картой

    if (btnPickInModal) btnPickInModal.addEventListener('click', function(){
      _userPickedManually = true; // помечаем, что пользователь выбрал точку вручную
      _pickActive = true;
      hideGeoModal();
      if (zoneEl) { zoneEl.className = 'alert alert-warning'; zoneEl.textContent = t('pick_instruction', '👆 Klikni na mapu, kde parkuješ.'); }
      const el = document.getElementById('map-frame'); if (el) el.style.cursor = 'crosshair';
      setTimeout(()=>{ if (_pickActive){ _pickActive=false; if (el) el.style.cursor=''; } }, 15000);
    });
    if (btnPick) btnPick.addEventListener('click', function(){
      _userPickedManually = true; // помечаем, что пользователь выбрал точку вручную
      _pickActive = true;
      if (zoneEl) { zoneEl.className = 'alert alert-warning'; zoneEl.textContent = t('pick_instruction', '👆 Klikni na mapu, kde parkuješ.'); }
      const el = document.getElementById('map-frame'); if (el) el.style.cursor = 'crosshair';
      setTimeout(()=>{ if (_pickActive){ _pickActive=false; if (el) el.style.cursor=''; } }, 15000);
    });

    // --- мок как раньше ---
    if (hasMock()) {
      const lat = DEFAULT_CENTER.lat, lng = DEFAULT_CENTER.lng;
      if (zoneEl) { zoneEl.className = 'alert alert-info'; zoneEl.textContent = tpl(t('dev_mock', '🧪 DEV: {{lat}}, {{lng}}'), { lat: lat.toFixed(6), lng: lng.toFixed(6) }); }
      setHelpLL(lat, lng, 'gps');
      afterPosition(lat, lng);
      hideGeoModal();
      return;
    }

    // автостарт гео; если невозможно — покажем модалку с выбором
    function startGeo() {
      if (_geoInFlight) return;      // защита от дубликатов
      _geoInFlight = true;

      if (!('geolocation' in navigator)) { 
        _geoInFlight = false;
        askModal(t('geo_not_supported', '❌ Prehliadač nepodporuje určovanie polohy.')); 
        return; 
      }
      if (zoneEl) { zoneEl.className = 'alert alert-info'; zoneEl.textContent = t('geo_getting', '⏳ Získavame tvoju polohu...'); }
      if (waitMsg) waitMsg.style.display = 'block';

      navigator.geolocation.getCurrentPosition(
        pos => {
          const lat = +pos.coords.latitude.toFixed(6);
          const lng = +pos.coords.longitude.toFixed(6);
          setHelpLL(lat, lng, 'gps');
          afterPosition(lat, lng);
          if (waitMsg) waitMsg.style.display = 'none';
          hideGeoModal();
          _geoInFlight = false;
        },
        err => {
          if (waitMsg) waitMsg.style.display = 'none';
          _geoInFlight = false;
          askModal(err && err.code === 1
            ? t('geo_denied_modal', '❌ Prístup k polohe bol zamietnutý. Povoliť geolokáciu alebo vybrať bod ručne?')
            : t('geo_unavailable_modal', '❌ Poloha nie je dostupná. Skús znova alebo vyber bod ručne.')
          );
        },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    }

    function askModal(message) {
        if (message) setGeoWaitMsg(message);
        setGeoWaitMsg(message || t('geo_need_enable_modal', 'ℹ️ Zapni určovanie polohy v nastaveniach a potom sa sem vráť, alebo vyber bod ručne.'));
        showGeoModal();          // показать модалку
        startPermissionWatch();  // начать слушать изменение разрешений и возврат на вкладку
    }

    if ('geolocation' in navigator) {
      if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'geolocation' }).then(p => {
          if (p.state === 'denied') askModal(t('geo_denied_short', 'ℹ️ Geolokácia je vypnutá. Povoliť alebo vybrať bod ručne?'));
          else startGeo(); // granted alebo prompt
        }).catch(() => startGeo());
      } else startGeo();
    } else {
      askModal(t('geo_not_supported', '❌ Prehliadač nepodporuje určovanie polohy.'));
    }
  }

  window.addEventListener('load', initGeo);
})();
