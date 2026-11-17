// Lightweight Standalone Toastr Notification Engine
window.toastr = (function() {
  function getContainer() {
    let c = document.getElementById('toast-container');
    if (!c) {
      c = document.createElement('div');
      c.id = 'toast-container';
      document.body.appendChild(c);
    }
    return c;
  }

  function show(type, message, title, options) {
    if (!message) return null;
    options = options || {};
    const container = getContainer();
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;

    const icons = {
      success: '✓',
      error: '✕',
      warning: '⚠',
      info: 'ℹ'
    };

    const iconEl = document.createElement('div');
    iconEl.className = 'toast-icon';
    iconEl.textContent = icons[type] || 'ℹ';
    toast.appendChild(iconEl);

    if (title) {
      const titleEl = document.createElement('div');
      titleEl.className = 'toast-title';
      titleEl.textContent = title;
      toast.appendChild(titleEl);
    }

    const msgEl = document.createElement('div');
    msgEl.className = 'toast-message';
    msgEl.textContent = message;
    toast.appendChild(msgEl);

    const progressEl = document.createElement('div');
    progressEl.className = 'toast-progress';
    const duration = options.timeOut || 4200;
    progressEl.style.animationDuration = duration + 'ms';
    toast.appendChild(progressEl);

    function remove() {
      toast.classList.add('toast-closing');
      setTimeout(function() {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 260);
    }

    const timer = setTimeout(remove, duration);

    toast.addEventListener('click', function() {
      clearTimeout(timer);
      remove();
    });

    container.appendChild(toast);
    return toast;
  }

  return {
    success: function(msg, title, opt) { return show('success', msg, title || 'Success', opt); },
    error: function(msg, title, opt) { return show('error', msg, title || 'Error', opt); },
    warning: function(msg, title, opt) { return show('warning', msg, title || 'Warning', opt); },
    info: function(msg, title, opt) { return show('info', msg, title || 'Notice', opt); }
  };
})();

(function(){
const $=s=>document.querySelector(s), flood=$('#flood-data');
let map=null, markers=[], selected=null;
let lastChartData = null;

// Form Validation and Submission Error Handling via Toastr
document.addEventListener('submit', function(e) {
  const form = e.target;
  if (!form || form.tagName !== 'FORM') return;

  if (!form.checkValidity()) {
    e.preventDefault();
    e.stopPropagation();

    const invalidEl = form.querySelector(':invalid');
    if (invalidEl) {
      invalidEl.classList.add('is-invalid');
      invalidEl.focus();

      let labelText = '';
      const label = invalidEl.closest('label');
      if (label) {
        const clone = label.cloneNode(true);
        clone.querySelectorAll('input, select, textarea, .req, small').forEach(x => x.remove());
        labelText = clone.textContent.replace(/[*:]/g, '').trim();
      }
      if (!labelText) {
        labelText = invalidEl.getAttribute('placeholder') || invalidEl.getAttribute('name') || 'Required field';
      }

      const reason = invalidEl.validationMessage || 'This field is required.';
      toastr.error(labelText + ': ' + reason, 'Form submission failed');

      invalidEl.addEventListener('input', function onInput() {
        invalidEl.classList.remove('is-invalid');
        invalidEl.removeEventListener('input', onInput);
      });
    } else {
      toastr.error('Please complete all required fields correctly before submitting.', 'Form submission failed');
    }
  }
}, true);

// Mobile Navigation Toggle
const navToggle = $('#nav-toggle');
const navMenu = $('#nav-menu');
if (navToggle && navMenu) {
  navToggle.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = navMenu.classList.toggle('is-open');
    navToggle.classList.toggle('is-active', isOpen);
    navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  });

  document.addEventListener('click', (e) => {
    if (!navMenu.contains(e.target) && !navToggle.contains(e.target) && navMenu.classList.contains('is-open')) {
      navMenu.classList.remove('is-open');
      navToggle.classList.remove('is-active');
      navToggle.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && navMenu.classList.contains('is-open')) {
      navMenu.classList.remove('is-open');
      navToggle.classList.remove('is-active');
      navToggle.setAttribute('aria-expanded', 'false');
    }
  });

  navMenu.querySelectorAll('.nav-link').forEach(link => {
    link.addEventListener('click', () => {
      navMenu.classList.remove('is-open');
      navToggle.classList.remove('is-active');
      navToggle.setAttribute('aria-expanded', 'false');
    });
  });
}

const fmt=v=>v===null||v===undefined?'—':Number(v).toLocaleString(undefined,{maximumFractionDigits:1})+' m³/s';

function setPlace(name,lat,lon){
  if(flood){
    flood.dataset.lat=lat;
    flood.dataset.lon=lon;
    flood.dataset.place=name;
    $('#place-label').textContent=name;
    loadFlood(lat,lon);
    loadRisk(lat,lon);
    if(map){
      map.setView([lat,lon],8);
      setTimeout(()=>{ map.invalidateSize(); }, 200);
    }
  }
}

async function loadFlood(lat,lon){
 $('#discharge-now').textContent='…';$('#flood-source').textContent='Requesting river telemetry data…';
 try{
  const r=await fetch(`api/flood.php?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}`);
  const d=await r.json();if(!r.ok||d.error)throw Error(d.error||'Provider unavailable');
  const day=d.daily, dates=day.time||[], vals=day.river_discharge||[];
  const rows=dates.map((date,i)=>({date,value:vals[i]})).filter(x=>x.value!==null&&x.value!==undefined);
  if(!rows.length)throw Error('No river-discharge values available at this grid point.');
  const today=new Date().toISOString().slice(0,10);
  let idx=rows.findIndex(x=>x.date>=today);if(idx<0)idx=rows.length-1;
  const current=rows[Math.max(0,idx-1)]||rows[0], future=rows.slice(idx);
  $('#discharge-now').textContent=fmt(current.value);$('#discharge-date').textContent='Latest modelled daily discharge · '+current.date;
  const recent=rows.slice(Math.max(0,idx-7),idx),avg=recent.length?recent.reduce((a,x)=>a+Number(x.value),0)/recent.length:null;
  const trend=avg===null?'Not enough history':Number(current.value)>avg*1.15?'Above recent average':Number(current.value)<avg*.85?'Below recent average':'Near recent average';
  $('#flow-trend').textContent=trend;
  const peak=future.length?Math.max(...future.map(x=>Number(x.value))):Math.max(...rows.map(x=>Number(x.value)));
  $('#flow-peak').textContent=fmt(peak);$('#grid-point').textContent=Number(d.latitude).toFixed(2)+', '+Number(d.longitude).toFixed(2);
  $('#flood-source').textContent=d.source+' · retrieved '+new Date(d.retrieved_at).toLocaleString()+'. '+d.note;
  lastChartData = { rows, idx };
  drawChart(rows,idx);
  $('#geo-status').textContent='Pakistan river telemetry loaded';
 }catch(err){
  $('#discharge-now').textContent='Unavailable';
  $('#discharge-date').textContent='No reliable model value at selected coordinates';
  $('#flow-trend').textContent='—';
  $('#flow-peak').textContent='—';
  $('#flood-source').textContent=err.message;
  $('#discharge-chart').innerHTML='<p class="muted">No discharge series available for this location. Try a point closer to a major river or select a configured monitoring station.</p>';
 }
}

function drawChart(rows,idx){
 const el=$('#discharge-chart');
 if(!el) return;
 const rectWidth = el.getBoundingClientRect().width;
 const w = Math.max(280, Math.round(rectWidth || el.clientWidth || 800));
 const isMobile = w < 520;
 const h = isMobile ? 200 : 220;
 const pad = isMobile ? 22 : 32;
 const vals=rows.map(x=>Number(x.value)),min=Math.min(...vals),max=Math.max(...vals),range=max-min||1;
 const x=i=>pad+(w-2*pad)*i/Math.max(1,rows.length-1),y=v=>h-pad-(h-2*pad)*(v-min)/range;
 const pts=rows.map((r,i)=>`${x(i).toFixed(1)},${y(Number(r.value)).toFixed(1)}`).join(' ');
 const splitIndex = Math.min(idx,rows.length-1);
 const split=rows[splitIndex]?x(splitIndex):w/2;
 const step = isMobile ? (w < 380 ? 4 : 3) : (w < 700 ? 3 : 2);
 let labels=rows.map((r,i)=>i%step===0?`<text x="${x(i).toFixed(1)}" y="${h-6}" text-anchor="middle" class="chart-label">${r.date.slice(5)}</text>`:'').join('');
 el.innerHTML=`<svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="xMidYMid meet" role="img" aria-label="River discharge history and forecast chart" style="width:100%;height:auto;display:block;"><line x1="${pad}" y1="${h-pad}" x2="${w-pad}" y2="${h-pad}" class="axis"/><line x1="${pad}" y1="${pad}" x2="${pad}" y2="${h-pad}" class="axis"/><line x1="${split.toFixed(1)}" y1="${pad}" x2="${split.toFixed(1)}" y2="${h-pad}" class="split-line"/><polyline points="${pts}" class="discharge-line"/><circle cx="${x(Math.max(0,Math.min(idx,rows.length-1))).toFixed(1)}" cy="${y(vals[Math.max(0,Math.min(idx,rows.length-1))]).toFixed(1)}" r="${isMobile ? 3.5 : 4.5}" class="chart-point"/>${labels}<text x="${pad}" y="14" class="chart-label">${fmt(max)}</text><text x="${pad}" y="${h-pad+13}" class="chart-label">${fmt(min)}</text></svg><div class="chart-split"><span>← Historical / modelled</span><span>Forecast →</span></div>`;
}

// Window resize & orientation change handler
let resizeDebounce = null;
window.addEventListener('resize', () => {
  clearTimeout(resizeDebounce);
  resizeDebounce = setTimeout(() => {
    if (lastChartData && lastChartData.rows) {
      drawChart(lastChartData.rows, lastChartData.idx);
    }
    if (map) {
      map.invalidateSize();
    }
  }, 120);
});

if(flood){const lat=Number(flood.dataset.lat),lon=Number(flood.dataset.lon);loadFlood(lat,lon);}

const geo=$('#use-location');
if(geo) {
  geo.addEventListener('click',()=>{
    if(!navigator.geolocation){alert('Geolocation is not supported by your browser.');return;}
    geo.textContent='Requesting location…';
    navigator.geolocation.getCurrentPosition(pos=>{
      geo.textContent='◎ Use my location';
      setPlace('Your current location',pos.coords.latitude,pos.coords.longitude);
    },()=>{
      geo.textContent='◎ Use my location';
      alert('Location permission was denied. Search for a place or use the map.');
    },{enableHighAccuracy:true,timeout:12000,maximumAge:300000});
  });
}

async function searchPlace(){
  const q=$('#city').value.trim();
  if(!q)return;
  $('#geo-status').textContent='Searching location…';
  try{
    const r=await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q='+encodeURIComponent(q));
    const a=await r.json();
    if(!a.length)throw Error('Location not found.');
    setPlace(a[0].display_name,Number(a[0].lat),Number(a[0].lon));
  }catch(e){
    $('#geo-status').textContent=e.message;
  }
}

$('#search-place')?.addEventListener('click',searchPlace);
$('#city')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();searchPlace();}});

async function loadFFD(){
  const body=$('#ffd-gauge-rows');
  if(!body)return;
  try{
    const r=await fetch('api/ffd.php');
    const d=await r.json();
    if(!r.ok)throw Error(d.error||'FFD unavailable');
    const rows=d.records||[];
    body.innerHTML=rows.length?rows.map(x=>`<tr><td><b>${esc(x.river_name)}</b></td><td>${esc(x.station_name)}</td><td class="num-cell"><b>${x.discharge_cusecs===null?'—':Number(x.discharge_cusecs).toLocaleString()+' cusecs'}</b></td><td><span class="pill status-${String(x.flood_status||'').toLowerCase().replace(/\s+/g,'-')}">${esc(x.flood_status||'Not published')}</span></td><td><span class="date-cell">${esc(x.source_updated_label||x.fetched_at||'—')}</span></td></tr>`).join(''):'<tr><td colspan="5" class="muted">No parseable FFD river-flow rows. Open the official FFD portal; no substitute data is shown.</td></tr>';
    $('#ffd-sync-status').textContent=rows.length?`${rows.length} FFD records · updated`:'FFD feed unavailable';
    $('#ffd-note').textContent=d.note+(d.last_sync?.message?' Last sync: '+d.last_sync.message:'');
  }catch(e){
    $('#ffd-sync-status').textContent='FFD feed unavailable';
    body.innerHTML=`<tr><td colspan="5" class="muted">${esc(e.message)}. Official source remains linked below.</td></tr>`;
  }
}

async function loadRisk(lat,lon){
  const el=$('#risk-outlook');
  if(!el)return;
  try{
    const r=await fetch(`api/prediction.php?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}`);
    const d=await r.json();
    if(!r.ok||d.error)throw Error(d.error||'Forecast unavailable');
    if(!d.available){el.textContent=d.message;return;}
    el.innerHTML=`<p class="muted small">${esc(d.method)} · Historical baseline P90: <b>${Number(d.historical_p90_m3s).toLocaleString()} m³/s</b></p><div class="card card-table" style="margin-top:12px"><div class="table-scroll-hint"><span>↔</span> Swipe horizontally to view full table</div><div class="table-wrap"><table class="table table-enterprise"><thead><tr><th>Date</th><th>Forecast flow</th><th>Relative to baseline</th><th>Risk band</th></tr></thead><tbody>${d.forecasts.map(x=>`<tr><td><span class="date-cell">${esc(x.date)}</span></td><td class="num-cell"><b>${Number(x.forecast_discharge_m3s).toLocaleString()} m³/s</b></td><td class="num-cell">${(x.relative_to_historical_p90*100).toFixed(0)}%</td><td><span class="pill pill-${esc(String(x.risk_band).toLowerCase())}">${esc(x.risk_band)}</span></td></tr>`).join('')}</tbody></table></div></div><p class="notice" style="margin-top:14px">${esc(d.warning)}</p>`;
  }catch(e){el.textContent=e.message;}
}

loadFFD();
setInterval(loadFFD,15*60*1000);

if(flood){loadRisk(Number(flood.dataset.lat),Number(flood.dataset.lon));}

const mapEl=$('#map');
if(mapEl&&window.L){
 map=L.map('map',{zoomControl:true,scrollWheelZoom:false}).setView([30.3753,69.3451],6);
 L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap contributors',maxZoom:18}).addTo(map);
 const pts=JSON.parse(mapEl.dataset.points||'[]'); 
 const floodLayers=JSON.parse(mapEl.dataset.layers||'[]');
 pts.forEach(p=>{
   const m=L.marker([p.lat,p.lon]).addTo(map).bindPopup(`<div class="map-popup-card"><b>${esc(p.name)}</b><div class="muted small">${esc(p.kind)}</div><div class="popup-metric">Water level: <b>${p.water===null?'Unavailable':esc(p.water+' m')}</b></div><div class="popup-metric">Rainfall: <b>${p.rain===null?'Unavailable':esc(p.rain+' mm')}</b></div><button class="pin-select btn" data-lat="${p.lat}" data-lon="${p.lon}" data-name="${esc(p.name)}" type="button">View river outlook</button></div>`);
   markers.push({marker:m,region:'Pakistan'});
 });
 floodLayers.forEach(layer=>{
   fetch('../storage/flood_layers/'+encodeURIComponent(layer.geojson_file)).then(r=>{if(!r.ok)throw Error('Layer unavailable');return r.json();}).then(g=>{
     L.geoJSON(g,{style:{color:'#c34437',weight:2,fillColor:'#e65a49',fillOpacity:.28}}).addTo(map).bindPopup(`<div class="map-popup-card"><b>${esc(layer.title)}</b><div class="muted small">${esc(layer.source)}</div><div>${esc(layer.observed_at||'Observation time not specified')}</div></div>`);
   }).catch(()=>{});
 });

 const gfmButton=$('#gfm-toggle'); 
 let gfmOverlay=null;
 if(gfmButton){
  gfmButton.addEventListener('click',async()=>{
   if(gfmOverlay){
     map.removeLayer(gfmOverlay);
     gfmOverlay=null;
     gfmButton.classList.remove('active');
     gfmButton.textContent='Show live satellite flood layer';
     return;
   }
   gfmButton.textContent='Loading satellite layer…';
   try{
    const base=mapEl.dataset.gfmWms;
    const cap=await fetch('api/gfm_capabilities.php');
    if(!cap.ok)throw Error('Copernicus GFM WMS is not responding.');
    const xml=new DOMParser().parseFromString(await cap.text(),'text/xml');
    const candidates=[...xml.querySelectorAll('Layer Layer')].map(el=>({name:el.querySelector(':scope > Name')?.textContent?.trim(),title:el.querySelector(':scope > Title')?.textContent?.trim()||''})).filter(x=>x.name);
    const chosen=candidates.find(x=>/observed.?flood.?extent/i.test(x.name+' '+x.title))||
                 candidates.find(x=>/flood.?extent/i.test(x.name+' '+x.title));
    if(!chosen)throw Error('No observed flood-extent layer was advertised by the GFM service.');
    gfmOverlay=L.tileLayer.wms(base,{layers:chosen.name,format:'image/png',transparent:true,version:'1.1.1',opacity:.72,attribution:'Copernicus EMS Global Flood Monitoring · Sentinel-1'}).addTo(map);
    gfmButton.classList.add('active');
    gfmButton.textContent='Hide satellite flood layer';
    $('#geo-status').textContent='Copernicus GFM: '+chosen.title;
   }catch(err){
    gfmButton.textContent='Show live satellite flood layer';
    $('#geo-status').textContent=err.message;
    alert(err.message+' You can still open the official GFM portal from the data sources.');
   }
  });
 }

 map.on('click',e=>setPlace('Selected map location',e.latlng.lat,e.latlng.lng));
 map.on('popupopen',e=>{
   const b=e.popup.getElement().querySelector('.pin-select');
   if(b)b.addEventListener('click',()=>setPlace(b.dataset.name,Number(b.dataset.lat),Number(b.dataset.lon)));
 });
 document.querySelectorAll('[data-layer]').forEach(b=>b.addEventListener('click',()=>{
   document.querySelectorAll('[data-layer]').forEach(x=>x.classList.remove('active'));
   b.classList.add('active');
   markers.forEach(x=>{
     if(b.dataset.layer==='all'||x.region===b.dataset.layer){
       if(!map.hasLayer(x.marker))x.marker.addTo(map);
     } else map.removeLayer(x.marker);
   });
 }));

 // Invalidate map size after layout stabilizes
 setTimeout(()=>{ if(map) map.invalidateSize(); }, 300);
}

function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
})();