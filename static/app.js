/* app.js - 前端单页逻辑（搜索 / 聚合结果 / 详情 / 播放 / 收藏） */
'use strict';

const CONFIG = {};
let state = { q: '', source: 'all', page: 1, sources: [] };

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
    const sel = document.getElementById('sourceSel');
    sel.innerHTML = '<option value="all">全部源</option>' +
        state.sources.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');

    document.getElementById('search').addEventListener('input', debounce(onSearchInput, 400));
    sel.addEventListener('change', () => { state.source = sel.value; doSearch(state.q, state.source, 1); });

    // URL 参数：?q= 预填搜索；?s=&id= 直接详情
    const params = new URLSearchParams(location.search);
    const q = params.get('q');
    if (q) { document.getElementById('search').value = q; doSearch(q, 'all', 1); }
    else if (params.get('s') && params.get('id')) { openDetail(params.get('s'), params.get('id')); }
    else { doSearch('', 'all', 1); } // 首页展示最新
}

function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
function onSearchInput(e) { state.q = e.target.value.trim(); doSearch(state.q, state.source, 1); }

async function doSearch(q, source, page) {
    state.q = q; state.source = source; state.page = page;
    showView('home');
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
    const pic = it.pic ? `<img loading="lazy" src="${esc(it.pic)}" onerror="imgFallback(this)">` : '<div class="no-pic">无图</div>';
    return `<div class="card" onclick="openDetail(${it.source},'${encodeURIComponent(it.id)}')">
        <div class="poster">${pic}<span class="badge">${esc(it.source_name)}</span>${it.remarks?`<span class="remark">${esc(it.remarks)}</span>`:''}</div>
        <div class="title">${esc(it.name)}</div>
        <div class="meta">${esc(it.type)}${it.year?' · '+esc(it.year):''}${it.area?' · '+esc(it.area):''}</div>
    </div>`;
}

async function openDetail(source, id) {
    showView('detail');
    const box = document.getElementById('detail');
    box.innerHTML = '<div class="loading">加载详情…</div>';
    try {
        const d = await api(`api_detail&s=${source}&id=${encodeURIComponent(id)}`);
        if (d.error) { box.innerHTML = '<div class="empty">' + esc(d.error) + '</div>'; return; }
        renderDetail(d);
        history.replaceState(null, '', `index.php?s=${source}&id=${encodeURIComponent(id)}`);
    } catch (e) {
        box.innerHTML = '<div class="empty">详情加载失败：' + esc(e.message) + '</div>';
    }
}

function renderDetail(d) {
    window._detailData = d;
    const box = document.getElementById('detail');
    const play = d.play || [];
    let tabs = '', episodes = '';
    if (play.length) {
        tabs = play.map((p, i) => `<button class="tab ${i===0?'active':''}" data-i="${i}" onclick="switchSrc(${i})">${esc(p.name)}</button>`).join('');
    }
    box.innerHTML = `
      <div class="detail-head">
        <div class="d-poster">${d.pic?`<img src="${esc(d.pic)}" onerror="imgFallback(this)">`:''}</div>
        <div class="d-info">
          <h2>${esc(d.name)} <small>${esc(d.remarks||'')}</small></h2>
          <p class="line">${esc(d.type)} ${d.year?'· '+esc(d.year):''} ${d.area?'· '+esc(d.area):''}</p>
          <p class="line">主演：${esc(d.actor)}</p>
          <p class="line">导演：${esc(d.director)}</p>
          <p class="desc">${esc(d.des)}</p>
          <button class="btn" onclick="addFav(${d.source},'${encodeURIComponent(d.id)}','${encodeURIComponent(d.name)}','${encodeURIComponent(d.pic||'')}')">★ 收藏</button>
        </div>
      </div>
      <div class="player" id="player"></div>
      <div class="src-tabs" id="srcTabs">${tabs}</div>
      <div class="episodes" id="episodes"></div>
    `;
    if (play.length) switchSrc(0);
}

let _dp = null;
function switchSrc(i) {
    const box = document.getElementById('detail');
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
            <div class="poster">${it.pic?`<img src="${esc(it.pic)}" onerror="imgFallback(this)">`:'<div class="no-pic">无图</div>'}<span class="badge">${esc(it.source)}</span></div>
            <div class="title">${esc(it.name)}</div>
        </div>`).join('');
    } catch (e) { list.innerHTML = '<div class="empty">加载失败</div>'; }
}

function showView(id) {
    ['home', 'detail', 'favView'].forEach(v => document.getElementById(v).style.display = (v === id ? 'block' : 'none'));
}

window.openDetail = openDetail;
window.doSearch = doSearch;
window.switchSrc = switchSrc;
window.playEp = playEp;
window.addFav = addFav;
window.showFav = showFav;

init();
