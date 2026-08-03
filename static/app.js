/* app.js - 前端单页逻辑（搜索 / 聚合结果 / 详情弹窗 / 播放 / 收藏 / 历史）
   整体首页展示方法学习 LibreTV：居中渐变标题 + 搜索栏(首页按钮) + 最近搜索 + 源胶囊 + 海报网格 + 详情弹窗 + 历史抽屉 */
'use strict';

const CONFIG = {};
let state = { q: '', source: 'all', page: 1, sources: [] };
const RECENT_KEY = 'lphptv_recent';
const HIST_KEY = 'lphptv_history';

function proxy(url) {
    return '/proxy.php?u=' + encodeURIComponent(url) + '&t=' + encodeURIComponent(CONFIG.proxy_token || '');
}
function isM3u8(url) { return /\.m3u8(\?|$)/i.test(url); }
function esc(s) { return (s ?? '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function imgFallback(el) { el.style.background = '#222'; el.removeAttribute('src'); }

async function api(path) {
    const r = await fetch('index.php?r=' + path);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
}

async function init() {
    try {
        const c = await api('config');
        Object.assign(CONFIG, c);
    } catch (e) { console.warn('config load fail', e); }
    document.title = CONFIG.site_name || '影视聚合';

    const sres = await api('sources').catch(() => ({ sources: [] }));
    state.sources = sres.sources || [];
    renderPills();
    renderRecent();
    renderHistory();

    document.getElementById('search').addEventListener('keydown', e => { if (e.key === 'Enter') submitSearch(); });

    const params = new URLSearchParams(location.search);
    const q = params.get('q');
    if (q) { document.getElementById('search').value = q; doSearch(q, state.source, 1); }
    else if (params.get('s') && params.get('id')) { openDetail(params.get('s'), params.get('id')); }
    else { doSearch('', state.source, 1); } // 首页展示最新
}

function renderPills() {
    const box = document.getElementById('sourcePills');
    const pills = [{ id: 'all', name: '全部源' }].concat(state.sources);
    box.innerHTML = pills.map(s =>
        `<button class="pill ${s.id === state.source ? 'active' : ''}" data-id="${esc(s.id)}" onclick="pickSource('${esc(s.id)}')">${esc(s.name)}</button>`
    ).join('');
}

function pickSource(id) {
    state.source = id;
    document.querySelectorAll('#sourcePills .pill').forEach(p => p.classList.toggle('active', p.dataset.id === id));
    doSearch(state.q, state.source, 1);
}

function onSearchInput(e) {
    const v = e.target.value;
    document.getElementById('clearBtn').style.display = v ? 'flex' : 'none';
    state.q = v.trim();
    debounceSearch();
}
function clearSearch() {
    const inp = document.getElementById('search');
    inp.value = ''; state.q = '';
    document.getElementById('clearBtn').style.display = 'none';
    doSearch('', state.source, 1);
}
function submitSearch() {
    const inp = document.getElementById('search');
    state.q = inp.value.trim();
    document.getElementById('clearBtn').style.display = inp.value ? 'flex' : 'none';
    if (state.q) pushRecent(state.q);
    doSearch(state.q, state.source, 1);
}

let _t;
function debounceSearch() { clearTimeout(_t); _t = setTimeout(() => doSearch(state.q, state.source, 1), 450); }

function pushRecent(q) {
    let arr = [];
    try { arr = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); } catch (e) {}
    arr = arr.filter(x => x !== q);
    arr.unshift(q);
    arr = arr.slice(0, 8);
    localStorage.setItem(RECENT_KEY, JSON.stringify(arr));
    renderRecent();
}
function renderRecent() {
    const box = document.getElementById('recent');
    let arr = [];
    try { arr = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); } catch (e) {}
    if (!arr.length) { box.innerHTML = ''; return; }
    box.innerHTML = arr.map(q => `<span class="chip" onclick="runRecent('${esc(q)}')">${esc(q)}</span>`).join('');
}
function runRecent(q) {
    document.getElementById('search').value = q; state.q = q;
    document.getElementById('clearBtn').style.display = 'flex';
    doSearch(q, state.source, 1);
}

async function doSearch(q, source, page) {
    state.q = q; state.source = source; state.page = page;
    showView('home');
    closeDetail();
    const loading = document.getElementById('loading');
    const results = document.getElementById('results');
    loading.style.display = 'block'; results.innerHTML = '';
    try {
        const data = await api(`api_search&q=${encodeURIComponent(q)}&source=${encodeURIComponent(source)}&page=${page}`);
        loading.style.display = 'none';
        if (!data.results || !data.results.length) {
            results.innerHTML = '<div class="empty">没有结果，换个关键词或源试试</div>';
            document.getElementById('pager').innerHTML = '';
            return;
        }
        results.innerHTML = data.results.map(cardHtml).join('');
        document.getElementById('pager').innerHTML =
            `<button ${page>1?'':'disabled'} onclick="doSearch(state.q,state.source,${page-1})">上一页</button>` +
            `<span>第 ${page} 页</span>` +
            `<button onclick="doSearch(state.q,state.source,${page+1})">下一页</button>`;
    } catch (e) {
        loading.style.display = 'none';
        results.innerHTML = '<div class="empty">搜索失败：' + esc(e.message) + '</div>';
    }
}

function cardHtml(it) {
    const safeId = encodeURIComponent(it.id);
    const safeName = esc(it.name);
    const sourceInfo = it.source_name
        ? `<span class="badge">${esc(it.source_name)}</span>` : '';
    // 跨源合并：同剧多源时显示源数量徽标
    const multi = (it.sources && it.sources.length > 1)
        ? `<span class="badge multi" title="来自多个源，可在详情里切换">${it.sources.length}源</span>` : '';
    const remark = it.remarks ? `<span class="remark">${esc(it.remarks)}</span>` : '';
    const pic = it.pic
        ? `<img loading="lazy" src="${esc(it.pic)}" alt="${safeName}" onerror="imgFallback(this)">`
        : '<div class="no-pic">无封面</div>';
    const meta = [it.type, it.year, it.area].filter(Boolean).map(esc).join(' · ');
    // 把同剧的所有源（source,id）序列化成 data 属性，供点击时带入详情切换器
    const srcData = (it.sources && it.sources.length)
        ? encodeURIComponent(JSON.stringify(it.sources))
        : encodeURIComponent(JSON.stringify([{ source: it.source, id: it.id, source_name: it.source_name }]));
    return `<div class="card" onclick="openDetail(${it.source},'${safeId}','${srcData}')">
        <div class="poster">${pic}${sourceInfo}${multi}${remark}<div class="pgrad"></div></div>
        <div class="title">${safeName}</div>
        ${meta ? `<div class="meta">${meta}</div>` : ''}
    </div>`;
}

/* ---------- 详情弹窗（学习 LibreTV #modal） ---------- */
async function openDetail(source, id, srcData) {
    const m = document.getElementById('modal');
    const c = document.getElementById('modalContent');
    c.innerHTML = '<div class="loading">加载详情…</div>';
    m.classList.add('show');
    document.body.style.overflow = 'hidden';
    let sources = [];
    try { sources = JSON.parse(decodeURIComponent(srcData || '')) || []; } catch (e) { sources = []; }
    if (!sources.length) sources = [{ source: source, id: id }];
    try {
        const d = await api(`api_detail&s=${source}&id=${encodeURIComponent(id)}`);
        if (d.error) { c.innerHTML = '<div class="empty">' + esc(d.error) + '</div>'; return; }
        d._sources = sources; // 携带同剧多源，供源切换器使用
        renderDetail(d);
        history.replaceState(null, '', `index.php?s=${source}&id=${encodeURIComponent(id)}`);
    } catch (e) {
        c.innerHTML = '<div class="empty">详情加载失败：' + esc(e.message) + '</div>';
    }
}

function closeDetail() {
    const m = document.getElementById('modal');
    if (!m.classList.contains('show')) return;
    m.classList.remove('show');
    document.body.style.overflow = '';
    if (_dp) { try { _dp.destroy(); } catch (e) {} _dp = null; }
}

function renderDetail(d) {
    window._detailData = d;
    const c = document.getElementById('modalContent');
    const play = d.play || [];
    let tabs = '';
    if (play.length) {
        tabs = play.map((p, i) => `<button class="tab ${i===0?'active':''}" data-i="${i}" onclick="switchSrc(${i})">${esc(p.name)}</button>`).join('');
    }
    const meta = [d.type, d.year, d.area].filter(Boolean).map(esc).join(' · ');
    // 同剧多源切换器（跨源）：与单源内的播放源分组(src-tabs)区分
    const sources = d._sources || [];
    let srcSwitch = '';
    if (sources.length > 1) {
        const cur = (d.source != null && d.id != null) ? (d.source + '_' + d.id) : '';
        srcSwitch = '<div class="src-switch"><span class="lbl">源：</span>' + sources.map((s, i) => {
            const key = s.source + '_' + s.id;
            const active = key === cur ? ' active' : '';
            return `<button class="ss ${active}" data-i="${i}" onclick="switchSource(${i})">${esc(s.source_name || ('源'+(i+1)))}</button>`;
        }).join('') + '</div>';
    }
    c.innerHTML = `
      <div class="detail-head">
        <div class="d-poster">${d.pic?`<img src="${esc(d.pic)}" onerror="imgFallback(this)">`:''}</div>
        <div class="d-info">
          <h2>${esc(d.name)} <small>${esc(d.remarks||'')}</small></h2>
          <p class="line">${meta}</p>
          <p class="line">主演：${esc(d.actor)}</p>
          <p class="line">导演：${esc(d.director)}</p>
          <p class="desc">${esc(d.des)}</p>
          <button class="btn" onclick="addFav(${d.source},'${encodeURIComponent(d.id)}','${encodeURIComponent(d.name)}','${encodeURIComponent(d.pic||'')}')">★ 收藏</button>
        </div>
      </div>
      ${srcSwitch}
      <div class="player" id="player"></div>
      <div class="src-tabs" id="srcTabs">${tabs}</div>
      <div class="episodes" id="episodes"></div>
    `;
    if (play.length) switchSrc(0);
}

// 跨源切换：同剧在不同源里重新拉取详情并替换当前弹窗内容
async function switchSource(i) {
    const d = window._detailData;
    if (!d || !d._sources || !d._sources[i]) return;
    const s = d._sources[i];
    const c = document.getElementById('modalContent');
    c.innerHTML = '<div class="loading">加载其它源…</div>';
    if (_dp) { try { _dp.destroy(); } catch (e) {} _dp = null; }
    try {
        const nd = await api(`api_detail&s=${s.source}&id=${encodeURIComponent(s.id)}`);
        if (nd.error) { c.innerHTML = '<div class="empty">' + esc(nd.error) + '</div>'; return; }
        nd._sources = d._sources;
        window._detailData = nd;
        renderDetail(nd);
    } catch (e) {
        c.innerHTML = '<div class="empty">其它源详情加载失败：' + esc(e.message) + '</div>';
    }
}

let _dp = null;
function switchSrc(i) {
    const d = window._detailData;
    if (!d) return;
    const src = d.play[i];
    document.querySelectorAll('#srcTabs .tab').forEach(t => t.classList.toggle('active', +t.dataset.i === i));
    const eps = document.getElementById('episodes');
    eps.innerHTML = (src.episodes && src.episodes.length)
        ? src.episodes.map((ep, j) => `<button class="ep ${j===0?'active':''}" onclick="playEp(${i},${j})">${esc(ep.name||('第'+(j+1)+'集'))}</button>`).join('')
        : '<div class="empty">该源暂无播放地址</div>';
    if (src.episodes && src.episodes.length) playEp(i, 0);
}

function playEp(i, j) {
    const d = window._detailData;
    const ep = d.play[i].episodes[j];
    document.querySelectorAll('#episodes .ep').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('#episodes .ep')[j].classList.add('active');
    pushHistory({ source: d.source, id: d.id, name: d.name, pic: d.pic });
    playUrl(ep.url);
}

function playUrl(url) {
    const c = document.getElementById('player');
    c.innerHTML = '<div id="dp"></div>';
    if (_dp) { try { _dp.destroy(); } catch (e) {} }
    _dp = new DPlayer({
        container: document.getElementById('dp'),
        video: { url: proxy(url), type: isM3u8(url) ? 'hls' : 'auto' },
        autoplay: true, theme: '#e50914',
    });
}

/* ---------- 观看历史（localStorage 抽屉） ---------- */
function pushHistory(item) {
    let arr = [];
    try { arr = JSON.parse(localStorage.getItem(HIST_KEY) || '[]'); } catch (e) {}
    arr = arr.filter(x => !(x.source === item.source && x.id === item.id));
    arr.unshift(item);
    arr = arr.slice(0, 30);
    localStorage.setItem(HIST_KEY, JSON.stringify(arr));
    renderHistory();
}
function renderHistory() {
    const box = document.getElementById('historyList');
    let arr = [];
    try { arr = JSON.parse(localStorage.getItem(HIST_KEY) || '[]'); } catch (e) {}
    if (!arr.length) { box.innerHTML = '<div class="empty">暂无观看记录</div>'; return; }
    box.innerHTML = arr.map((it, idx) => {
        const pic = it.pic ? `<img src="${esc(it.pic)}" onerror="imgFallback(this)">` : '<div class="no-pic">无</div>';
        return `<div class="hist-item" onclick="openDetail(${it.source},'${encodeURIComponent(it.id)}')">
            <div class="hist-pic">${pic}</div>
            <div class="hist-name">${esc(it.name)}</div>
            <button class="hist-del" onclick="event.stopPropagation();delHistory(${idx})" aria-label="删除">×</button>
        </div>`;
    }).join('');
}
function delHistory(idx) {
    let arr = [];
    try { arr = JSON.parse(localStorage.getItem(HIST_KEY) || '[]'); } catch (e) {}
    arr.splice(idx, 1);
    localStorage.setItem(HIST_KEY, JSON.stringify(arr));
    renderHistory();
}
function clearHistory() {
    if (!confirm('清空全部观看历史？')) return;
    localStorage.removeItem(HIST_KEY);
    renderHistory();
}
function toggleHistory() {
    document.getElementById('historyPanel').classList.toggle('show');
}

async function addFav(source, id, name, pic) {
    await fetch('index.php?r=fav&act=add', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ vod_id: decodeURIComponent(id), source: source, name: decodeURIComponent(name), pic: decodeURIComponent(pic) })
    }).catch(() => {});
    alert('已加入收藏');
}

async function showFav() {
    showView('favView');
    const list = document.getElementById('favList');
    list.innerHTML = '<div class="loading">加载…</div>';
    try {
        const data = await api('fav&act=list');
        const items = data.list || [];
        if (!items.length) { list.innerHTML = '<div class="empty">还没有收藏</div>'; return; }
        list.innerHTML = items.map(it => `<div class="card" onclick="openDetail(${it.source},'${encodeURIComponent(it.vod_id)}')">
            <div class="poster">${it.pic?`<img src="${esc(it.pic)}" onerror="imgFallback(this)">`:'<div class="no-pic">无图</div>'}<span class="badge">${esc(it.source)}</span><div class="pgrad"></div></div>
            <div class="title">${esc(it.name)}</div>
        </div>`).join('');
    } catch (e) { list.innerHTML = '<div class="empty">加载失败</div>'; }
}

function goHome() { showView('home'); closeDetail(); document.getElementById('search').value = state.q; }
function showView(id) {
    ['home', 'favView'].forEach(v => document.getElementById(v).style.display = (v === id ? 'block' : 'none'));
    if (id === 'home') window.scrollTo({ top: 0, behavior: 'smooth' });
}

window.openDetail = openDetail;
window.closeDetail = closeDetail;
window.doSearch = doSearch;
window.switchSrc = switchSrc;
window.playEp = playEp;
window.addFav = addFav;
window.showFav = showFav;
window.goHome = goHome;
window.pickSource = pickSource;
window.clearSearch = clearSearch;
window.submitSearch = submitSearch;
window.onSearchInput = onSearchInput;
window.runRecent = runRecent;
window.toggleHistory = toggleHistory;
window.clearHistory = clearHistory;
window.delHistory = delHistory;

init();
