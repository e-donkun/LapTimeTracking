/* 計測端末アプリ
 *
 * - 通過時刻は「端末時刻 + サーバとの時刻差 (offset)」でサーバ時刻に揃えて記録する。
 *   offset は ../api/?r=time を数回呼び、往復遅延が最小のサンプルから求める。
 * - 記録は localStorage に保存し、未送信分を数秒おきにサーバへ送る（通信断でも記録は失われない）。
 * - 削除は論理削除。削除・復元も同期され、サーバは更新時刻の新しい方を採用する。
 * - ビブは 2 桁。1 桁目を押した瞬間の時刻を通過時刻とし、2 桁目で確定する。
 */
(function () {
  'use strict';

  const API = '../api/index.php?r=';
  const PENDING_EXPIRE_MS = 10000; // 1 桁目から 2 桁目までの猶予
  const SYNC_INTERVAL_MS = 3000;
  const HEARTBEAT_MS = 15000;
  const CLOCK_RESYNC_MS = 120000;

  // ------------------------------------------------------------------ ユーティリティ

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pad2 = (n) => String(n).padStart(2, '0');
  const bib2 = (n) => pad2(n);

  function dur(ms) {
    if (ms == null || isNaN(ms)) return '--:--.-';
    const neg = ms < 0;
    ms = Math.abs(ms);
    const t = Math.floor((ms % 1000) / 100);
    const s = Math.floor(ms / 1000);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const body = h > 0 ? h + ':' + pad2(m) + ':' + pad2(s % 60) + '.' + t : m + ':' + pad2(s % 60) + '.' + t;
    return (neg ? '-' : '') + body;
  }
  function clock(ms) {
    if (ms == null) return '--:--:--';
    const d = new Date(ms);
    return pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds()) + '.' + Math.floor(d.getMilliseconds() / 100);
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    const b = new Uint8Array(16);
    crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  const store = {
    get(key, def) {
      try {
        const v = localStorage.getItem('ltt.' + key);
        return v == null ? def : JSON.parse(v);
      } catch (e) {
        return def;
      }
    },
    set(key, value) {
      try {
        localStorage.setItem('ltt.' + key, JSON.stringify(value));
        return true;
      } catch (e) {
        toast('端末への保存に失敗しました（容量不足の可能性）', 'error');
        return false;
      }
    },
  };

  let toastTimer;
  function toast(msg, type) {
    const el = $('#toast');
    el.textContent = msg;
    el.className = 'toast show ' + (type || '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = 'toast'; }, type === 'error' || type === 'warn' ? 3500 : 1800);
  }
  function vibrate(p) {
    if (navigator.vibrate) {
      try { navigator.vibrate(p); } catch (e) { /* ignore */ }
    }
  }

  // ------------------------------------------------------------------ 端末情報・時刻同期

  const device = store.get('device', null) || { uuid: uuid(), name: '' };
  // 端末名は必須にしない（未設定ならID から自動で付ける。一覧画面の「✎」で変更可）
  if (!device.name) device.name = '端末-' + device.uuid.slice(0, 4).toUpperCase();
  store.set('device', device);

  const clockState = store.get('clock', { offset: null, rtt: null, at: 0 });
  let clockSyncedThisSession = false;

  function serverNow() {
    return Date.now() + (clockState.offset || 0);
  }
  function clockKnown() {
    return clockState.offset != null;
  }

  async function fetchJson(route, opts, params) {
    opts = opts || {};
    const headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});
    const code = app.compId ? store.get('code.' + app.compId, '') : '';
    if (code) headers['X-Device-Code'] = code;
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), opts.timeout || 8000);
    let url = API + encodeURIComponent(route);
    if (params) url += '&' + new URLSearchParams(params).toString();
    try {
      const res = await fetch(url, {
        method: opts.method || 'GET',
        headers,
        body: opts.body ? JSON.stringify(opts.body) : undefined,
        signal: ctrl.signal,
        cache: 'no-store',
        credentials: 'same-origin',
      });
      let json;
      try {
        json = await res.json();
      } catch (e) {
        throw Object.assign(new Error('サーバ応答が不正です'), { network: true });
      }
      if (!json.ok) throw Object.assign(new Error(json.error || 'エラー'), { code: json.code, status: res.status });
      return json;
    } catch (e) {
      if (e.name === 'AbortError' || e instanceof TypeError) throw Object.assign(new Error('通信できません'), { network: true });
      throw e;
    } finally {
      clearTimeout(timer);
    }
  }

  async function syncClock() {
    const samples = [];
    for (let i = 0; i < 5; i++) {
      const t0 = Date.now();
      const p0 = performance.now();
      const res = await fetchJson('time', { timeout: 4000 });
      const rtt = performance.now() - p0;
      samples.push({ rtt, offset: res.now_ms - (t0 + rtt / 2) });
    }
    samples.sort((a, b) => a.rtt - b.rtt);
    const best = samples[0];
    clockState.offset = Math.round(best.offset);
    clockState.rtt = Math.round(best.rtt);
    clockState.at = Date.now();
    store.set('clock', clockState);
    clockSyncedThisSession = true;
  }

  // ------------------------------------------------------------------ アプリ状態

  const app = {
    compId: null,
    comp: null, // サーバの大会情報（キャッシュ）
    teams: {}, // bib => team
    passes: [], // この端末の記録
    online: null,
    syncing: false,
    lastSync: 0,
    lastError: '',
    pendingStart: null,
    view: '',
    tab: 'input',
    input: '',
    inputAt: null, // 1 桁目を押した時の {server, client, offset}
    lastRecord: null,
    results: null,
    race: null, // サーバ集計によるレース状態 {finished, finish_ms, ...}
  };

  function loadCompetition(id) {
    app.compId = id;
    const cache = store.get('comp.' + id, null);
    app.comp = cache ? cache.competition : null;
    app.teams = {};
    if (cache) cache.teams.forEach((t) => { app.teams[t.bib] = t; });
    app.rosterAt = cache ? cache.at : null;
    app.race = cache ? cache.race || null : null;
    app.passes = store.get('passes.' + id, []);
    app.pendingStart = store.get('pendingStart.' + id, null);
    app.results = null;
  }

  function savePasses() {
    store.set('passes.' + app.compId, app.passes);
  }

  async function fetchRoster(timeout) {
    const res = await fetchJson('competition', { timeout }, { id: app.compId });
    app.teams = {};
    res.teams.forEach((t) => { app.teams[t.bib] = t; });
    app.rosterAt = Date.now();
    applyCompetition(res.competition, res.race);
    store.set('comp.' + app.compId, { competition: app.comp, teams: res.teams, at: app.rosterAt, race: app.race });
    return res;
  }

  /**
   * サーバの大会情報を反映する。
   * 管理画面で「計測をリセット」されて計測回 (run_no) が進んでいれば、それより前の端末内の記録を退避して空にする。
   */
  function applyCompetition(comp, race) {
    if (!comp) return { startChanged: false, reset: false };
    const prevStart = app.comp ? app.comp.start_ms : undefined;
    app.comp = Object.assign({}, app.comp || {}, comp);
    const run = comp.run_no || 1;
    let reset = false;
    const old = app.passes.filter((p) => (p.run_no || 1) < run);
    if (old.length) {
      store.set('archive.' + app.compId, store.get('archive.' + app.compId, []).concat(old));
      app.passes = app.passes.filter((p) => (p.run_no || 1) >= run);
      savePasses();
      app.lastRecord = null;
      reset = true;
    }
    if (app.pendingStart && (app.pendingStart.run_no || 1) < run) {
      app.pendingStart = null;
      store.set('pendingStart.' + app.compId, null);
      reset = true;
    }
    if (reset) {
      app.race = null;
      toast('管理画面で計測がリセットされました。「計測スタート」からやり直せます', 'warn');
    }
    setRace(race);
    const cache = store.get('comp.' + app.compId, null);
    if (cache) {
      cache.competition = app.comp;
      cache.race = app.race;
      store.set('comp.' + app.compId, cache);
    }
    return { startChanged: prevStart !== app.comp.start_ms, reset };
  }

  function pendingPasses() {
    return app.passes.filter((p) => p.synced_rev !== p.updated_ms);
  }

  /** この端末での、そのビブの n 回目（= n 走）を返す */
  function legOf(p) {
    const list = app.passes.filter((x) => x.bib === p.bib && !x.deleted).sort((a, b) => a.time_ms - b.time_ms);
    const i = list.findIndex((x) => x.uuid === p.uuid);
    return i < 0 ? null : i + 1;
  }

  function describe(p) {
    const team = app.teams[p.bib];
    const leg = p.deleted ? null : legOf(p);
    const runner = team && leg ? team.runners[leg - 1] : null;
    return { team, leg, runner };
  }

  // ------------------------------------------------------------------ レース終了（全走者の記録が揃ったらタイマー停止）

  function setRace(race) {
    if (!race) return;
    const was = app.race && app.race.finished;
    app.race = race;
    if (race.finished && !was && app.view === 'run') {
      toast('全走者の記録が揃いました。タイマーを停止します');
      vibrate([100, 60, 100]);
    }
  }

  /** この端末の記録だけで判定（オフライン時の代替）。DNS/DNF/DQ のチームは対象外 */
  function localFinishMs() {
    const start = app.comp && app.comp.start_ms;
    const teams = Object.values(app.teams).filter((t) => !t.status);
    if (!start || !teams.length) return null;
    let last = 0;
    for (const t of teams) {
      const need = t.runners.length || (app.comp.team_size || 3);
      const mine = app.passes.filter((p) => p.bib === t.bib && !p.deleted && p.time_ms >= start).sort((a, b) => a.time_ms - b.time_ms);
      if (mine.length < need) return null;
      last = Math.max(last, mine[need - 1].time_ms);
    }
    return last;
  }

  /** タイマーを止める時刻（全走者ゴール）。未完了なら null */
  function raceFinishMs() {
    if (app.race && app.race.finished) return app.race.finish_ms;
    if (app.online === false) return localFinishMs();
    return null;
  }

  // ------------------------------------------------------------------ 同期

  async function syncNow(force) {
    if (app.syncing || !app.compId) return;
    app.syncing = true;
    try {
      if (!clockSyncedThisSession || Date.now() - clockState.at > CLOCK_RESYNC_MS) {
        await syncClock();
      }
      // 時刻未同期のまま記録したものを、端末時刻 + 判明した offset で補正
      let fixed = false;
      app.passes.forEach((p) => {
        if (p.offset_ms == null && clockKnown()) {
          p.time_ms = p.client_ms + clockState.offset;
          p.offset_ms = clockState.offset;
          p.updated_ms = Date.now();
          fixed = true;
        }
      });
      if (fixed) savePasses();

      if (app.pendingStart) {
        const ps = app.pendingStart;
        const est = ps.offset_ms == null ? ps.client_ms + clockState.offset : ps.server_ms;
        try {
          const res = await fetchJson('start', {
            method: 'POST',
            body: { competition_id: app.compId, device: deviceInfo(), estimated_ms: est, run_no: ps.run_no || 1 },
          });
          app.pendingStart = null;
          store.set('pendingStart.' + app.compId, null);
          if (applyCompetition(res.competition).startChanged) render();
        } catch (e) {
          if (e.network || e.code === 'device_code') throw e;
          // サーバに拒否されたスタートは破棄する（通過記録の同期を止めないため）
          app.pendingStart = null;
          store.set('pendingStart.' + app.compId, null);
          toast('オフライン時のスタートを登録できませんでした：' + e.message, 'error');
          render();
        }
      }

      const pending = pendingPasses().slice(0, 500);
      if (pending.length || force || Date.now() - app.lastSync > HEARTBEAT_MS) {
        const res = await fetchJson('sync', {
          method: 'POST',
          body: {
            competition_id: app.compId,
            device: deviceInfo(),
            passes: pending.map((p) => ({
              uuid: p.uuid, run_no: p.run_no || 1, bib: p.bib, time_ms: p.time_ms, client_ms: p.client_ms, offset_ms: p.offset_ms,
              deleted: p.deleted ? 1 : 0, deleted_ms: p.deleted_ms || null, updated_ms: p.updated_ms,
            })),
          },
          timeout: 15000,
        });
        const acc = {};
        res.accepted.forEach((a) => { acc[a.uuid] = a.updated_ms; });
        app.passes.forEach((p) => {
          if (acc[p.uuid] === p.updated_ms) p.synced_rev = p.updated_ms;
        });
        (res.rejected || []).forEach((u) => {
          const p = app.passes.find((x) => x.uuid === u);
          if (p) p.rejected = true;
        });
        savePasses();
        if (res.competition) {
          const r = applyCompetition(res.competition, res.race);
          if (r.startChanged || r.reset) render();
        }
        app.lastSync = Date.now();
      }
      app.online = true;
      app.lastError = '';
    } catch (e) {
      app.online = !e.network;
      app.lastError = e.message;
      if (e.code === 'device_code') {
        askCode();
      }
    } finally {
      app.syncing = false;
      renderStatus();
    }
  }

  function deviceInfo() {
    return { uuid: device.uuid, name: device.name, offset_ms: clockState.offset, rtt_ms: clockState.rtt };
  }

  setInterval(() => {
    if (!app.compId) return;
    if (pendingPasses().length || app.pendingStart || Date.now() - app.lastSync > HEARTBEAT_MS) syncNow();
  }, SYNC_INTERVAL_MS);
  window.addEventListener('online', () => syncNow(true));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) syncNow(true); });

  // ------------------------------------------------------------------ ルーティング

  function route() {
    const m = location.hash.match(/^#\/c\/(\d+)(\/run)?/);
    if (m) {
      const id = Number(m[1]);
      if (app.compId !== id) loadCompetition(id);
      app.view = m[2] ? 'run' : 'comp';
    } else {
      app.compId = null;
      app.view = 'list';
    }
    render();
    if (app.view === 'list') loadList();
    if (app.view === 'comp') refreshComp();
    if (app.view === 'run') {
      syncNow(true);
      if (!app.rosterAt) refreshComp();
    }
  }
  window.addEventListener('hashchange', route);

  function render() {
    const v = $('#view');
    document.body.dataset.view = app.view;
    if (app.view === 'list') v.innerHTML = viewList();
    else if (app.view === 'comp') v.innerHTML = viewComp();
    else v.innerHTML = viewRun();
    renderStatus();
    if (app.view === 'run') renderTab();
  }

  // ------------------------------------------------------------------ 大会一覧

  function viewList() {
    const cached = store.get('complist', []);
    return '<header class="bar"><h1>大会一覧</h1><button class="bar-btn" data-act="rename">' + esc(device.name || '端末名を設定') + ' ✎</button></header>' +
      '<main class="pad"><p class="hint" id="list-msg">読み込み中…</p><ul class="list" id="comp-list">' + listItems(cached) + '</ul>' +
      '<p class="hint small">端末ID: ' + esc(device.uuid.slice(0, 8)) + '</p></main>';
  }
  function listItems(list) {
    if (!list.length) return '';
    return list.map((c) =>
      '<li><a href="#/c/' + c.id + '" class="list-item">' +
      '<strong>' + esc(c.name) + '</strong>' +
      '<span>' + esc([c.event_date, c.location].filter(Boolean).join('　')) + '</span>' +
      '<span class="tags">' + (c.start_ms ? '<em class="tag tag-run">スタート済 ' + clock(c.start_ms).slice(0, 8) + '</em>' : '<em class="tag">未スタート</em>') +
      (c.needs_code ? '<em class="tag">🔒 パスコード</em>' : '') + '</span></a></li>').join('');
  }
  async function loadList() {
    try {
      const res = await fetchJson('competitions');
      store.set('complist', res.competitions);
      if (app.view !== 'list') return;
      $('#comp-list').innerHTML = listItems(res.competitions);
      $('#list-msg').textContent = res.competitions.length ? '計測する大会を選んでください' : '大会が登録されていません（管理画面で作成してください）';
    } catch (e) {
      if (app.view !== 'list') return;
      $('#list-msg').textContent = 'オフラインのため前回の一覧を表示しています';
      $('#list-msg').className = 'hint warn';
    }
  }

  // ------------------------------------------------------------------ 大会トップ（計測スタート）

  function viewComp() {
    const c = app.comp;
    const teamCount = Object.keys(app.teams).length;
    const started = c && c.start_ms;
    return '<header class="bar"><a class="bar-btn" href="#/">‹ 一覧</a><h1>' + esc(c ? c.name : '読み込み中…') + '</h1><span id="status" class="status"></span></header>' +
      '<main class="pad comp">' +
      (c ? '<p class="meta">' + esc([c.event_date, c.location].filter(Boolean).join('　')) + '</p>' : '') +
      '<div class="card">' +
      '<dl class="kv">' +
      '<dt>スタート</dt><dd id="comp-start">' + (started ? '<b>' + clock(c.start_ms) + '</b>（サーバ時刻）' : (app.pendingStart ? '送信待ち（オフラインで記録）' : '未記録')) + '</dd>' +
      (started && app.race && app.race.finished ? '<dt>状況</dt><dd><b class="ok">全走者ゴール</b>（' + dur(app.race.elapsed_ms) + '）</dd>' : '') +
      '<dt>選手情報</dt><dd>' + (app.rosterAt ? teamCount + ' チーム（' + clock(app.rosterAt).slice(0, 5) + ' 取得）' : '未取得') + '</dd>' +
      '<dt>この端末</dt><dd>' + esc(device.name || '（未設定）') + ' <button class="link" data-act="rename">変更</button></dd>' +
      '<dt>時刻同期</dt><dd id="clock-info">' + clockInfo() + '</dd>' +
      '<dt>記録</dt><dd>' + app.passes.filter((p) => !p.deleted).length + ' 件（未送信 ' + pendingPasses().length + '）</dd>' +
      '</dl></div>' +
      (started || app.pendingStart
        ? '<button class="btn-start started" data-act="start">計測画面へ<small>' +
          (app.race && app.race.finished ? '全走者ゴール・計測終了' : started ? 'スタート済み ' + clock(c.start_ms) : 'スタート送信待ち') + '</small></button>' +
          '<p class="hint small">やり直す場合は、管理画面の「大会設定 → 計測をリセット」を使います。</p>'
        : '<button class="btn-start" data-act="start">計測スタート<small>押した時刻がレースのスタートになります</small></button>') +
      '<button class="btn-sub" data-act="reload">選手情報を再読み込み</button>' +
      '</main>';
  }
  function clockInfo() {
    if (!clockKnown()) return '<span class="warn">未同期</span>';
    return '差 ' + (clockState.offset / 1000).toFixed(2) + ' 秒 / 遅延 ' + clockState.rtt + ' ms';
  }

  async function refreshComp() {
    try {
      await fetchRoster();
      if (!clockSyncedThisSession) await syncClock();
      app.online = true;
    } catch (e) {
      app.online = !e.network;
      if (e.code === 'device_code') return askCode();
      if (e.status === 404) {
        toast('大会が見つかりません', 'error');
        location.hash = '#/';
        return;
      }
      if (!app.rosterAt) toast('選手情報を取得できません（' + e.message + '）', 'warn');
    }
    if (app.view === 'comp' || app.view === 'run') render();
  }

  async function pressStart() {
    // 管理画面でリセット・修正されている可能性があるため、まず最新の状態を確認する（オフラインなら端末内の情報で続行）
    try {
      await fetchRoster(3000);
      app.online = true;
    } catch (e) {
      if (e.code === 'device_code') return askCode();
      if (!e.network) return toast(e.message, 'error');
      app.online = false;
    }
    const c = app.comp;
    if ((c && c.start_ms) || app.pendingStart) {
      goRun();
      return;
    }
    const ok = await confirmDialog('計測スタート', 'レースのスタート時刻を記録します。<br><b>号砲と同時に</b>押してください。<br><small>他の端末が先に押していれば、その時刻が使われます。</small>', 'スタート');
    if (!ok) return;
    const client = Date.now();
    const runNo = (app.comp && app.comp.run_no) || 1;
    try {
      const res = await fetchJson('start', { method: 'POST', body: { competition_id: app.compId, device: deviceInfo(), run_no: runNo } });
      applyCompetition(res.competition);
      if (res.stale_run) {
        toast('計測がリセットされていました。もう一度「計測スタート」を押してください', 'warn');
        render();
        return;
      }
      toast(res.created ? 'スタートを記録しました ' + clock(res.start_ms) : '既にスタート済みです ' + clock(res.start_ms));
      vibrate(80);
    } catch (e) {
      if (!e.network) {
        if (e.code === 'device_code') askCode();
        toast(e.message, 'error');
        return;
      }
      // オフライン: 推定サーバ時刻を保存しておき、通信回復後に送信する
      app.pendingStart = { client_ms: client, server_ms: clockKnown() ? client + clockState.offset : null, offset_ms: clockState.offset, run_no: runNo };
      store.set('pendingStart.' + app.compId, app.pendingStart);
      toast('オフラインのため端末に記録しました。通信回復後に送信します', 'warn');
    }
    goRun();
  }

  function goRun() {
    const h = '#/c/' + app.compId + '/run';
    if (location.hash !== h) location.hash = h;
    else render();
  }

  // ------------------------------------------------------------------ 計測画面

  function viewRun() {
    const c = app.comp;
    return '<header class="bar bar-run"><a class="bar-btn" href="#/c/' + app.compId + '">‹</a>' +
      '<div class="bar-title"><span>' + esc(c ? c.name : '') + '</span><small id="now-clock"></small></div>' +
      '<span id="status" class="status"></span></header>' +
      '<div class="tab-body" id="tab-body"></div>' +
      '<nav class="tabbar">' +
      '<button data-tab="input">⌨<span>入力</span></button>' +
      '<button data-tab="history">☰<span>履歴</span></button>' +
      '<button data-tab="results">🏁<span>速報</span></button></nav>';
  }

  function renderTab() {
    $$('.tabbar button').forEach((b) => b.classList.toggle('active', b.dataset.tab === app.tab));
    const body = $('#tab-body');
    if (app.tab === 'input') body.innerHTML = viewInput();
    else if (app.tab === 'history') body.innerHTML = viewHistory();
    else {
      body.innerHTML = viewResults();
      loadResults();
    }
    if (app.tab === 'input') updateInputDisplay();
  }

  function viewInput() {
    return '<section class="timer"><div class="elapsed" id="elapsed">--:--.-</div><div class="timer-sub" id="timer-sub"></div>' +
      '<button class="btn-start-inline" data-act="start" id="run-start" hidden>計測スタート</button></section>' +
      '<section class="entry">' +
      '<label class="bib-input-wrap"><input id="bib-input" class="bib-input" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" placeholder="--" aria-label="ビブ番号"></label>' +
      '<div class="entry-info" id="entry-info"><span class="muted">ビブ番号（2桁）を入力</span></div>' +
      '</section>' +
      '<section class="last" id="last">' + lastHtml() + '</section>' +
      '<section class="keypad">' +
      [1, 2, 3, 4, 5, 6, 7, 8, 9].map((n) => '<button data-key="' + n + '">' + n + '</button>').join('') +
      '<button data-key="C" class="key-fn">C</button><button data-key="0">0</button><button data-key="BS" class="key-fn">⌫</button>' +
      '</section>';
  }

  function lastHtml() {
    const r = app.lastRecord && app.passes.find((p) => p.uuid === app.lastRecord);
    if (!r) return '<div class="last-empty">直前の記録はここに表示されます</div>';
    const d = describe(r);
    const start = app.comp && app.comp.start_ms;
    return '<div class="last-card' + (r.deleted ? ' deleted' : '') + (d.team ? '' : ' unknown') + '">' +
      '<span class="bib-big">' + bib2(r.bib) + '</span>' +
      '<div class="last-main"><b>' + (d.team ? esc(d.team.name) : '未登録のビブ') + '</b>' +
      '<span>' + (d.leg ? d.leg + '走 ' : '') + (d.runner ? esc(d.runner.name) : '') + '</span>' +
      '<span class="mono">' + (start ? dur(r.time_ms - start) : clock(r.time_ms)) + '</span></div>' +
      (r.deleted
        ? '<button class="btn-undo" data-act="restore" data-uuid="' + r.uuid + '">復元</button>'
        : '<button class="btn-undo" data-act="delete" data-uuid="' + r.uuid + '">取消</button>') +
      '</div>';
  }

  function keyInput(k) {
    if (k === 'C') {
      app.input = '';
      app.inputAt = null;
    } else if (k === 'BS') {
      app.input = app.input.slice(0, -1);
      if (!app.input) app.inputAt = null;
    } else if (/^\d$/.test(k)) {
      if (!app.input) {
        // 1 桁目を押した瞬間を通過時刻とする
        app.inputAt = { client: Date.now(), offset: clockState.offset };
      }
      app.input += k;
      if (app.input.length >= 2) {
        commit(app.input.slice(0, 2));
        return;
      }
    } else if (k === 'ENTER') {
      if (app.input.length === 1) commit('0' + app.input); // 1 桁なら 0 埋め
      return;
    }
    updateInputDisplay();
  }

  function commit(text) {
    const at = app.inputAt || { client: Date.now(), offset: clockState.offset };
    app.input = '';
    app.inputAt = null;
    const b = Number(text);
    const now = Date.now();
    const p = {
      uuid: uuid(),
      run_no: (app.comp && app.comp.run_no) || 1,
      bib: b,
      client_ms: at.client,
      offset_ms: at.offset,
      time_ms: at.client + (at.offset || 0),
      deleted: false,
      deleted_ms: null,
      updated_ms: now,
      synced_rev: null,
    };
    app.passes.push(p);
    savePasses();
    app.lastRecord = p.uuid;

    // 確認メッセージ
    const warns = [];
    const team = app.teams[b];
    if (!team) warns.push('未登録のビブです');
    const win = (app.comp && app.comp.merge_window_ms) || 10000;
    if (app.passes.some((x) => x !== p && x.bib === b && !x.deleted && Math.abs(x.time_ms - p.time_ms) <= win)) warns.push('直前に同じビブを記録済み（重複?）');
    const leg = legOf(p);
    if (team && leg > Math.max(team.runners.length, 1)) warns.push('全走者分の記録があります');
    if (!(app.comp && app.comp.start_ms) && !app.pendingStart) warns.push('スタート未記録');
    if (!clockKnown()) warns.push('時刻未同期（同期後に補正）');

    const d = describe(p);
    const label = bib2(b) + ' ' + (team ? team.name : '') + (d.leg ? ' ' + d.leg + '走' : '');
    if (warns.length) {
      toast(label + '：' + warns.join(' / '), 'warn');
      vibrate([60, 50, 60]);
    } else {
      toast('✓ ' + label + ' 記録');
      vibrate(30);
    }
    flash(warns.length ? 'warn' : 'ok');
    if (app.tab === 'input') {
      $('#last').innerHTML = lastHtml();
      updateInputDisplay();
    }
    renderStatus();
    syncNow();
  }

  function flash(kind) {
    const el = $('.entry');
    if (!el) return;
    el.classList.remove('flash-ok', 'flash-warn');
    void el.offsetWidth;
    el.classList.add('flash-' + kind);
  }

  function updateInputDisplay() {
    const input = $('#bib-input');
    if (!input) return;
    if (input.value !== app.input) input.value = app.input;
    const info = $('#entry-info');
    if (app.input && app.inputAt) {
      const start = app.comp && app.comp.start_ms;
      const t = app.inputAt.client + (app.inputAt.offset || 0);
      info.innerHTML = '<span class="pending">' + (start ? dur(t - start) : clock(t)) + ' で記録します</span>';
    } else {
      info.innerHTML = '<span class="muted">ビブ番号（2桁）を入力</span>';
    }
  }

  // ------------------------------------------------------------------ 履歴

  function viewHistory() {
    const start = app.comp && app.comp.start_ms;
    const list = app.passes.slice().sort((a, b) => b.time_ms - a.time_ms);
    const active = list.filter((p) => !p.deleted).length;
    let html = '<div class="hist-head"><input id="hist-filter" inputmode="numeric" maxlength="2" placeholder="ビブで絞込"><span>' + active + ' 件' +
      (list.length - active ? '（削除 ' + (list.length - active) + '）' : '') + '</span></div><ul class="hist">';
    if (!list.length) html += '<li class="hist-empty">まだ記録がありません</li>';
    list.forEach((p) => {
      const d = describe(p);
      const synced = p.synced_rev === p.updated_ms;
      html += '<li class="hist-item' + (p.deleted ? ' deleted' : '') + '" data-bib="' + bib2(p.bib) + '">' +
        '<span class="bib-sm">' + bib2(p.bib) + '</span>' +
        '<div class="hist-main"><b>' + (d.team ? esc(d.team.name) : '<span class="warn">未登録</span>') + '</b>' +
        '<span>' + (d.leg ? d.leg + '走 ' : '') + (d.runner ? esc(d.runner.name) : '') + '</span></div>' +
        '<div class="hist-time"><b class="mono">' + (start ? dur(p.time_ms - start) : '') + '</b><span class="mono">' + clock(p.time_ms) + '</span></div>' +
        '<span class="sync-ic" title="' + (synced ? '送信済み' : '未送信') + '">' + (p.rejected ? '⚠' : synced ? '✓' : '↑') + '</span>' +
        (p.deleted
          ? '<button class="btn-mini" data-act="restore" data-uuid="' + p.uuid + '">復元</button>'
          : '<button class="btn-mini danger" data-act="delete" data-uuid="' + p.uuid + '">削除</button>') +
        '</li>';
    });
    return html + '</ul>';
  }

  function setDeleted(uuidv, deleted) {
    const p = app.passes.find((x) => x.uuid === uuidv);
    if (!p) return;
    p.deleted = deleted;
    p.deleted_ms = deleted ? serverNow() : null;
    p.updated_ms = Math.max(Date.now(), (p.updated_ms || 0) + 1);
    savePasses();
    toast(deleted ? bib2(p.bib) + ' の記録を削除しました' : bib2(p.bib) + ' の記録を復元しました');
    if (app.tab === 'history') {
      const f = $('#hist-filter') ? $('#hist-filter').value : '';
      $('#tab-body').innerHTML = viewHistory();
      if (f) {
        $('#hist-filter').value = f;
        filterHistory();
      }
    } else if (app.tab === 'input') {
      $('#last').innerHTML = lastHtml();
    }
    renderStatus();
    syncNow();
  }

  function filterHistory() {
    const q = $('#hist-filter').value.trim();
    $$('.hist-item').forEach((li) => {
      li.hidden = q !== '' && li.dataset.bib !== q.padStart(2, '0') && !li.dataset.bib.startsWith(q);
    });
  }

  // ------------------------------------------------------------------ 速報

  function viewResults() {
    return '<div class="res-head"><span id="res-msg" class="muted">読み込み中…</span><button class="btn-mini" data-act="res-reload">更新</button></div><div id="res-list">' + resultsHtml() + '</div>';
  }

  async function loadResults() {
    try {
      const res = await fetchJson('results', {}, { id: app.compId });
      app.results = res.results;
      app.resultsAt = Date.now();
      if (app.tab !== 'results') return;
      $('#res-list').innerHTML = resultsHtml();
      $('#res-msg').textContent = clock(serverNow()).slice(0, 8) + ' 時点（全端末の集計）';
    } catch (e) {
      if (app.tab !== 'results' || !$('#res-msg')) return;
      $('#res-msg').textContent = (app.results ? '前回取得分を表示中・' : '') + e.message;
    }
  }

  function resultsHtml() {
    const r = app.results;
    if (!r) return '';
    const mine = {};
    app.passes.forEach((p) => { if (!p.deleted) mine[p.bib] = (mine[p.bib] || 0) + 1; });
    let html = '<table class="res"><thead><tr><th>順</th><th>ビブ</th><th>チーム</th><th>区間</th><th>記録</th></tr></thead><tbody>';
    r.teams.forEach((t) => {
      const lastLeg = t.legs_done > 0 ? t.legs[t.legs_done - 1] : null;
      const diff = (mine[t.bib] || 0) !== t.legs_done + t.extra.length;
      html += '<tr class="' + (t.flag_count ? 'flag' : '') + '">' +
        '<td>' + (t.is_open ? 'OP' : (t.rank || '')) + '</td>' +
        '<td><span class="bib-sm">' + t.bib_label + '</span></td>' +
        '<td>' + esc(t.name) + (diff ? '<div class="small warn">この端末 ' + (mine[t.bib] || 0) + ' 件</div>' : '') + '</td>' +
        '<td>' + t.legs_done + '/' + t.leg_count + '</td>' +
        '<td class="mono">' + (t.total_ms != null ? dur(t.total_ms) : (lastLeg ? '<span class="muted">' + dur(lastLeg.elapsed_ms) + '</span>' : '')) + '</td></tr>';
    });
    html += '</tbody></table>';
    if (r.unknown_bibs.length) {
      html += '<p class="warn small">未登録ビブ: ' + r.unknown_bibs.map((u) => bib2(u.bib)).join(', ') + '</p>';
    }
    return html + '<p class="small muted">黄色 = 端末間で差や記録漏れがあるチーム。「この端末 n 件」はこの端末の記録数がサーバ集計と異なることを示します。</p>';
  }

  // ------------------------------------------------------------------ ステータス・時計

  function renderStatus() {
    const el = $('#status');
    if (!el) return;
    const n = pendingPasses().length + (app.pendingStart ? 1 : 0);
    let cls = 'ok';
    let text = '同期済';
    if (app.online === false) {
      cls = 'off';
      text = 'オフライン' + (n ? ' ↑' + n : '');
    } else if (n) {
      cls = 'pend';
      text = '未送信 ' + n;
    } else if (app.online === null) {
      cls = 'pend';
      text = '接続中';
    }
    if (app.lastError && app.online !== false) {
      cls = 'off';
      text = 'エラー';
    }
    el.className = 'status ' + cls;
    el.textContent = text;
    el.title = app.lastError || '';
  }

  function tick() {
    if (app.view !== 'run') return;
    const now = serverNow();
    const nc = $('#now-clock');
    if (nc) nc.textContent = clock(now).slice(0, 8) + (clockKnown() ? '' : ' (未同期)');
    const el = $('#elapsed');
    if (el) {
      const start = app.comp && app.comp.start_ms;
      const ps = app.pendingStart;
      const s = start || (ps ? (ps.server_ms || ps.client_ms) : null);
      const finish = start ? raceFinishMs() : null;
      // 全走者の記録が揃ったら、最後のゴール時刻でタイマーを止める
      el.textContent = finish != null ? dur(finish - start) : s ? dur(Math.max(0, now - s)) : '--:--.-';
      const timer = $('.timer');
      if (timer) timer.classList.toggle('stopped', finish != null);
      const rs = $('#run-start');
      if (rs) rs.hidden = !!(start || ps);
      const sub = $('#timer-sub');
      if (sub) {
        sub.textContent = finish != null ? '全走者ゴール・計測終了（スタート ' + clock(start) + '）'
          : start ? 'スタート ' + clock(start) + (app.race && app.race.teams ? '　ゴール ' + app.race.teams_finished + '/' + app.race.teams + ' チーム' : '')
          : ps ? 'スタート送信待ち' : 'スタート未記録';
      }
    }
    // 1 桁目から時間が経ちすぎた入力は破棄（誤った時刻での記録を防ぐ）
    if (app.inputAt && Date.now() - app.inputAt.client > PENDING_EXPIRE_MS) {
      app.input = '';
      app.inputAt = null;
      updateInputDisplay();
      toast('入力が途中のまま時間が経過したため取り消しました', 'warn');
    }
  }
  setInterval(tick, 100);

  // ------------------------------------------------------------------ ダイアログ

  // <dialog> 非対応ブラウザ（iOS 15.3 以前など）では標準の confirm / prompt で代替する
  const HAS_DIALOG = typeof HTMLDialogElement === 'function' && typeof HTMLDialogElement.prototype.showModal === 'function';
  const plain = (html) => String(html).replace(/<br\s*\/?>/g, '\n').replace(/<[^>]+>/g, '');

  function dialog(html) {
    const dlg = $('#dlg');
    dlg.innerHTML = html;
    if (!dlg.open) dlg.showModal();
    return dlg;
  }

  function confirmDialog(title, body, okLabel) {
    if (!HAS_DIALOG) return Promise.resolve(window.confirm(plain(title) + '\n\n' + plain(body)));
    return new Promise((resolve) => {
      const dlg = dialog('<form method="dialog"><h2>' + title + '</h2><p>' + body + '</p><div class="dlg-actions">' +
        '<button value="cancel" class="btn-sub">キャンセル</button><button value="ok" class="btn-primary">' + esc(okLabel || 'OK') + '</button></div></form>');
      dlg.onclose = () => resolve(dlg.returnValue === 'ok');
    });
  }

  function saveName(name) {
    device.name = name.trim().slice(0, 20);
    store.set('device', device);
    render();
    syncNow(true);
  }

  function askName() {
    if (!HAS_DIALOG) {
      const n = window.prompt('この端末の名前（例: 計測1 山田）', device.name || '');
      if (n && n.trim()) saveName(n);
      return Promise.resolve();
    }
    return new Promise((resolve) => {
      const dlg = dialog('<form method="dialog"><h2>この端末の名前</h2><p class="small">管理画面で、どの端末の記録か見分けるために使います。</p>' +
        '<input name="n" maxlength="20" placeholder="例: 計測1 山田" value="' + esc(device.name) + '" required>' +
        '<div class="dlg-actions"><button value="cancel" class="btn-sub" formnovalidate>キャンセル</button><button value="ok" class="btn-primary">保存</button></div></form>');
      const input = $('input', dlg);
      setTimeout(() => input.focus(), 50);
      dlg.onclose = () => {
        if (dlg.returnValue === 'ok' && input.value.trim()) saveName(input.value);
        resolve();
      };
    });
  }

  let askingCode = false;
  function askCode() {
    if (askingCode) return;
    const saveCode = (code) => {
      store.set('code.' + app.compId, code.trim());
      refreshComp();
      syncNow(true);
    };
    if (!HAS_DIALOG) {
      const code = window.prompt('計測パスコード（管理者に確認してください）', '');
      if (code != null) saveCode(code);
      return;
    }
    askingCode = true;
    const dlg = dialog('<form method="dialog"><h2>計測パスコード</h2><p class="small">この大会は計測にパスコードが必要です。管理者に確認してください。</p>' +
      '<input name="c" autocomplete="off" required>' +
      '<div class="dlg-actions"><button value="cancel" class="btn-sub" formnovalidate>キャンセル</button><button value="ok" class="btn-primary">OK</button></div></form>');
    const input = $('input', dlg);
    setTimeout(() => input.focus(), 50);
    dlg.onclose = () => {
      askingCode = false;
      if (dlg.returnValue === 'ok') saveCode(input.value);
    };
  }

  // ------------------------------------------------------------------ イベント

  document.addEventListener('click', (e) => {
    const key = e.target.closest('[data-key]');
    if (key) {
      keyInput(key.dataset.key);
      return;
    }
    const tab = e.target.closest('[data-tab]');
    if (tab) {
      app.tab = tab.dataset.tab;
      renderTab();
      return;
    }
    const act = e.target.closest('[data-act]');
    if (!act) return;
    switch (act.dataset.act) {
      case 'rename': askName(); break;
      case 'start': pressStart(); break;
      case 'reload': refreshComp().then(() => toast('選手情報を更新しました')); break;
      case 'delete': setDeleted(act.dataset.uuid, true); break;
      case 'restore': setDeleted(act.dataset.uuid, false); break;
      case 'res-reload': loadResults(); break;
    }
  });

  // ダブルタップによる拡大を防止（テンキー連打のため）
  document.addEventListener('dblclick', (e) => { if (e.target.closest('.keypad')) e.preventDefault(); }, { passive: false });

  // テキストボックス（OS のキーボード）からの入力
  document.addEventListener('input', (e) => {
    if (e.target.id === 'bib-input') {
      const v = e.target.value.replace(/[０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xfee0)).replace(/\D/g, '');
      if (v.length > app.input.length && !app.input) app.inputAt = { client: Date.now(), offset: clockState.offset };
      app.input = v.slice(0, 2);
      if (!app.input) app.inputAt = null;
      if (app.input.length >= 2) commit(app.input);
      else updateInputDisplay();
    } else if (e.target.id === 'hist-filter') {
      filterHistory();
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.target.id === 'bib-input' && e.key === 'Enter') {
      e.preventDefault();
      keyInput('ENTER');
      return;
    }
    // 物理キーボード（タブレット等）: 入力欄以外にフォーカスがある時も数字を受け付ける
    if (app.view !== 'run' || app.tab !== 'input' || $('#dlg').open) return;
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    const map = { Backspace: 'BS', Escape: 'C', Enter: 'ENTER' };
    const k = /^\d$/.test(e.key) ? e.key : map[e.key];
    if (!k) return;
    e.preventDefault();
    keyInput(k);
  });

  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    navigator.serviceWorker.register('sw.js').catch(() => { /* 未対応環境では無視 */ });
  }
  if (navigator.storage && navigator.storage.persist) {
    navigator.storage.persist().catch(() => {});
  }

  route();
})();
