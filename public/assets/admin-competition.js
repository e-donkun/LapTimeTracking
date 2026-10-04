/* 管理画面: 大会詳細 */
(function () {
  'use strict';
  const L = window.LTT;
  const { api, esc, toast, dur, clock, bib } = L;
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  const ID = Number($('#app').dataset.id);
  const STATE = {
    finished: ['完走', 'badge-ok'], running: ['走行中', 'badge-run'], waiting: ['未出走', ''],
    dns: ['DNS', 'badge-danger'], dnf: ['DNF', 'badge-danger'], dq: ['DQ', 'badge-danger'],
  };
  const COMP_STATUS = { preparing: '準備中', active: '開催中', finished: '終了' };
  const METHOD = { median: '中央値', earliest: '最速', admin: '管理者入力' };

  const st = {
    comp: null,
    teams: [],
    devices: [],
    results: null,
    race: null, // 全走者の記録が揃ったか（タイマー停止）
    passes: null,
    tab: 'results',
    timers: {},
  };

  // ------------------------------------------------------------------ 共通

  async function loadComp() {
    const res = await api('admin.competition', { id: ID });
    st.comp = res.competition;
    st.teams = res.teams;
    st.devices = res.devices;
    st.race = res.race;
    renderHead();
  }

  function renderHead() {
    const c = st.comp;
    $('#comp-name').textContent = c.name;
    document.title = c.name + ' | Lap Time Tracking';
    $('#comp-meta').innerHTML = [c.event_date, c.location].filter(Boolean).map(esc).join('　') +
      '　<span class="badge ' + (c.status === 'active' ? 'badge-run' : c.status === 'finished' ? 'badge-ok' : '') + '">' + (COMP_STATUS[c.status] || c.status) + '</span>';
    $('#start-label').textContent = c.start_ms ? clock(c.start_ms) : '未記録';
    $('#run-no').textContent = c.run_no > 1 ? '（現在 ' + c.run_no + ' 回目の計測）' : '';
    $('#start-detail').innerHTML = c.start_ms
      ? esc(clock(c.start_ms)) + ' <span class="small muted">（' + esc(c.start_set_by || '') + '）</span>'
      : '未記録';
  }

  function tickElapsed() {
    const el = $('#elapsed');
    const label = $('#race-label');
    const r = st.race;
    if (!st.comp || !st.comp.start_ms) {
      el.textContent = '--:--.-';
      label.textContent = '';
    } else if (r && r.finished) {
      // 全走者の記録が揃ったら最後のゴール時刻でタイマーを止める
      el.textContent = dur(r.elapsed_ms);
      label.textContent = '全走者ゴール・計測終了';
    } else {
      el.textContent = dur(Math.max(0, L.serverNow() - st.comp.start_ms));
      label.textContent = r && r.teams ? 'ゴール ' + r.teams_finished + ' / ' + r.teams + ' チーム' : '';
    }
    const done = !!(st.comp && st.comp.start_ms && r && r.finished);
    el.classList.toggle('stopped', done);
    label.classList.toggle('done', done);
  }

  function teamByBib(b) {
    return st.teams.find((t) => t.bib === b);
  }

  // ------------------------------------------------------------------ タブ

  function showTab(name) {
    if (!$('[data-panel="' + name + '"]')) name = 'results';
    st.tab = name;
    $$('.tabs button').forEach((b) => b.classList.toggle('active', b.dataset.tab === name));
    $$('.tab-panel').forEach((p) => { p.hidden = p.dataset.panel !== name; });
    if (location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
    ({ results: loadResults, passes: loadPasses, teams: renderTeams, devices: loadDevices, settings: renderSettings }[name])();
  }
  $$('.tabs button').forEach((b) => b.addEventListener('click', () => showTab(b.dataset.tab)));

  // ------------------------------------------------------------------ 速報・集計

  let loadingResults = false;
  async function loadResults() {
    if (loadingResults) return;
    loadingResults = true;
    try {
      const res = await api('results', { id: ID });
      st.results = res.results;
      st.race = res.results.race;
      const rc = st.results.competition;
      if (st.comp && (rc.start_ms !== st.comp.start_ms || rc.status !== st.comp.status)) {
        st.comp.start_ms = rc.start_ms;
        loadComp();
      }
      renderResults();
      $('#res-updated').textContent = '更新 ' + clock(L.serverNow()).slice(0, 8);
    } catch (err) {
      $('#res-updated').textContent = '更新失敗: ' + err.message;
    } finally {
      loadingResults = false;
    }
  }

  function legCount() {
    let n = st.comp ? st.comp.team_size : 3;
    (st.results ? st.results.teams : []).forEach((t) => { n = Math.max(n, t.leg_count); });
    return n;
  }

  function isFlagged(c) {
    return c && c.flags.some((f) => f !== 'manual');
  }

  function renderResults() {
    const r = st.results;
    if (!r) return;
    const s = r.summary;
    const active = r.devices.filter((d) => d.active).length;
    $('#summary').innerHTML =
      '<span class="chip">チーム <b>' + s.teams + '</b></span>' +
      '<span class="chip">完走 <b>' + s.finished + '</b></span>' +
      '<span class="chip' + (s.flagged ? ' warn' : '') + '">要確認 <b>' + s.flagged + '</b></span>' +
      (s.unknown ? '<span class="chip warn">未登録ビブ <b>' + s.unknown + '</b></span>' : '') +
      '<span class="chip">計測端末 <b>' + active + '</b> / ' + r.devices.length + '</span>';

    const q = $('#res-filter').value.trim().toLowerCase();
    const flaggedOnly = $('#res-flagged').checked;
    const n = legCount();
    let html = '<thead><tr><th class="center">順位</th><th>ビブ</th><th>チーム</th><th>区分</th>';
    for (let i = 1; i <= n; i++) html += '<th>' + i + '走</th>';
    html += '<th class="num">チーム記録</th><th>状態</th></tr></thead><tbody>';

    const rows = r.teams.filter((t) => {
      if (flaggedOnly && !t.flag_count) return false;
      if (!q) return true;
      const hay = [t.bib_label, t.name, t.category].concat(t.legs.map((l) => (l.runner ? l.runner.name + ' ' + l.runner.kana : ''))).join(' ').toLowerCase();
      return hay.includes(q);
    });
    if (!rows.length) {
      html += '<tr><td colspan="' + (n + 6) + '" class="muted">' + (r.teams.length ? '該当するチームがありません' : '選手が登録されていません（「選手登録」タブから登録してください）') + '</td></tr>';
    }
    rows.forEach((t) => {
      const stt = STATE[t.state] || [t.state, ''];
      html += '<tr class="' + (t.is_open ? 'open' : '') + '">' +
        '<td class="rank">' + (t.is_open ? 'OP' : (t.rank || '')) + '</td>' +
        '<td><span class="bib">' + t.bib_label + '</span></td>' +
        '<td class="team-name">' + esc(t.name) + (t.is_open ? ' <span class="badge badge-open">オープン</span>' : '') +
          (t.category_rank ? '<div class="small muted">区分 ' + t.category_rank + '位</div>' : '') + '</td>' +
        '<td>' + esc(t.category) + '</td>';
      for (let i = 0; i < n; i++) {
        const leg = t.legs[i];
        if (!leg) { html += '<td class="leg pending"></td>'; continue; }
        const c = leg.crossing;
        const cls = ['leg'];
        if (!c) cls.push(i === t.legs_done && t.state === 'running' ? 'running' : 'pending');
        if (isFlagged(c)) cls.push('flagged');
        if (c && c.method === 'admin') cls.push('manual');
        html += '<td class="' + cls.join(' ') + '" data-bib="' + t.bib + '" data-leg="' + i + '" title="クリックで端末ごとの記録を表示">' +
          '<div class="runner">' + (leg.runner ? esc(leg.runner.name) : '<span class="muted">（未登録）</span>') + '</div>';
        if (c) {
          html += '<div><span class="split">' + dur(leg.split_ms) + '</span>' +
            (leg.split_rank ? ' <span class="srank">(' + leg.split_rank + ')</span>' : '') + '</div>' +
            '<div class="cum">' + dur(leg.elapsed_ms) + '</div>';
        } else {
          html += '<div class="split">' + (cls.includes('running') ? '走行中' : '—') + '</div>';
        }
        html += '</td>';
      }
      html += '<td class="total">' + (t.total_ms != null ? dur(t.total_ms) : '') + '</td>' +
        '<td><span class="badge ' + stt[1] + '">' + stt[0] + '</span>' +
        (t.extra.length ? ' <span class="badge badge-warn leg-extra" data-bib="' + t.bib + '" title="登録人数より多い通過があります">超過 ' + t.extra.length + '</span>' : '') +
        '</td></tr>';
    });
    html += '</tbody>';
    $('#res-table').innerHTML = html;

    // 未登録ビブ・スタート前
    let extra = '';
    if (r.unknown_bibs.length) {
      extra += '<div class="card"><h2>未登録ビブの記録</h2><p class="small muted">入力ミスの可能性があります。「通過記録」タブで除外するか、チームを登録してください。</p>' +
        '<table class="table"><thead><tr><th>ビブ</th><th>回目</th><th>通過時刻</th><th>経過</th><th>記録した端末</th></tr></thead><tbody>';
      r.unknown_bibs.forEach((u) => {
        u.crossings.forEach((c, i) => {
          extra += '<tr><td><span class="bib">' + bib(u.bib) + '</span></td><td>' + (i + 1) + '</td><td>' + clock(c.time_ms) + '</td><td>' + dur(c.elapsed_ms) + '</td><td>' +
            Object.keys(c.devices).map((d) => esc(r.device_names[d] || d)).join(', ') + '</td></tr>';
        });
      });
      extra += '</tbody></table></div>';
    }
    if (r.prestart.length) {
      extra += '<div class="card"><h2>スタート前の記録（集計対象外）</h2><table class="table"><thead><tr><th>ビブ</th><th>時刻</th><th>端末</th></tr></thead><tbody>' +
        r.prestart.map((p) => '<tr><td><span class="bib">' + bib(p.bib) + '</span></td><td>' + clock(p.time_ms) + '</td><td>' + esc(p.device_name) + '</td></tr>').join('') +
        '</tbody></table></div>';
    }
    $('#res-extra').innerHTML = extra;
  }

  $('#res-filter').addEventListener('input', renderResults);
  $('#res-flagged').addEventListener('change', renderResults);
  $('#res-table').addEventListener('click', (e) => {
    const td = e.target.closest('td.leg[data-bib]');
    if (td) return openCrossing(Number(td.dataset.bib), Number(td.dataset.leg));
    const ex = e.target.closest('.leg-extra');
    if (ex) openExtra(Number(ex.dataset.bib));
  });

  // ------------------------------------------------------------------ 通過の詳細（端末比較）

  async function ensurePasses() {
    const res = await api('admin.passes', { id: ID });
    st.passes = res.passes;
    st.devices = res.devices;
    return st.passes;
  }

  async function openCrossing(b, legIndex) {
    const t = st.results.teams.find((x) => x.bib === b);
    if (!t) return;
    const leg = t.legs[legIndex];
    await showCrossingDialog({
      title: '<span class="bib">' + t.bib_label + '</span> ' + esc(t.name) + ' — ' + (legIndex + 1) + '走 ' + (leg.runner ? esc(leg.runner.name) : ''),
      bib: b,
      crossing: leg.crossing,
      split: leg.split_ms,
      elapsed: leg.elapsed_ms,
    });
  }

  async function openExtra(b) {
    const t = st.results.teams.find((x) => x.bib === b);
    if (!t) return;
    await showCrossingDialog({
      title: '<span class="bib">' + t.bib_label + '</span> ' + esc(t.name) + ' — 登録人数を超える通過',
      bib: b,
      extra: t.extra,
    });
  }

  async function showCrossingDialog(o) {
    const passes = await ensurePasses();
    const names = st.results.device_names;
    const byId = {};
    passes.forEach((p) => { byId[p.id] = p; });
    const list = o.extra || (o.crossing ? [o.crossing] : []);
    let html = '<h2>' + o.title + '</h2>';
    if (!list.length) {
      html += '<p class="muted">この区間の通過はまだ記録されていません。</p>';
    }
    list.forEach((c) => {
      html += '<div class="card">' +
        '<p><strong>採用: ' + clock(c.time_ms) + '</strong>（' + (METHOD[c.method] || c.method) + '）' +
        (o.split != null && !o.extra ? '　ラップ <strong>' + dur(o.split) + '</strong>' : '') +
        (o.elapsed != null && !o.extra ? '　累計 ' + dur(o.elapsed) : '') +
        '　端末間の差 ' + (c.spread_ms / 1000).toFixed(1) + ' 秒</p>';
      if (c.flags.length) {
        html += '<p>' + c.flags.map((f) => '<span class="badge ' + (f === 'manual' ? 'badge-run' : 'badge-warn') + '">' + esc(st.results.flag_labels[f] || f) + '</span>').join(' ') + '</p>';
      }
      html += '<table class="table compare-table"><thead><tr><th>端末</th><th>記録時刻</th><th>採用との差</th><th></th></tr></thead><tbody>';
      c.pass_ids.map((id) => byId[id]).filter(Boolean).sort((a, b2) => a.time_ms - b2.time_ms).forEach((p) => {
        const diff = p.time_ms - c.time_ms;
        const isAdmin = p.source === 'admin';
        html += '<tr><td class="label">' + esc(isAdmin ? '管理者' : (names[p.device_uuid] || p.device_name || p.device_uuid)) + '</td>' +
          '<td>' + clock(p.time_ms) + '</td>' +
          '<td class="' + (diff > 0 ? 'diff-pos' : diff < 0 ? 'diff-neg' : '') + '">' + (diff >= 0 ? '+' : '') + (diff / 1000).toFixed(1) + ' 秒</td>' +
          '<td>' + (isAdmin
            ? '<button class="btn btn-small btn-danger-outline" data-act="delete" data-id="' + p.id + '">削除</button>'
            : '<button class="btn btn-small" data-act="exclude" data-id="' + p.id + '">集計から除外</button>') + '</td></tr>';
      });
      (c.missing || []).forEach((d) => {
        html += '<tr class="row-muted"><td class="label">' + esc(names[d] || d) + '</td><td colspan="3">記録なし</td></tr>';
      });
      html += '</tbody></table></div>';
    });
    html += '<form class="inline-form" id="form-cross-fix">' +
      '<label>正しい通過時刻を入力（管理者入力が優先されます）<input name="value" placeholder="' + (list[0] ? clock(list[0].time_ms) : '10:23:45.6') + '" required></label>' +
      '<button class="btn btn-primary" type="submit">確定</button></form>';
    $('#crossing-body').innerHTML = html;
    const dlg = $('#crossing-dialog');
    if (!dlg.open) dlg.showModal();

    $('#crossing-body').onclick = async (e) => {
      const btn = e.target.closest('button[data-act]');
      if (!btn) return;
      try {
        if (btn.dataset.act === 'exclude') {
          await api('admin.pass.exclude', { competition_id: ID, id: Number(btn.dataset.id), excluded: true });
          toast('除外しました（「通過記録」タブで戻せます）');
        } else {
          await api('admin.pass.delete', { competition_id: ID, id: Number(btn.dataset.id) });
          toast('管理者入力を削除しました');
        }
        dlg.close();
        loadResults();
      } catch (err) {
        toast(err.message, 'error');
      }
    };
    $('#form-cross-fix').onsubmit = async (e) => {
      e.preventDefault();
      try {
        await api('admin.pass.add', { competition_id: ID, bib: bib(o.bib), kind: 'clock', value: e.target.elements.value.value, note: '集計画面から修正' });
        toast('通過時刻を確定しました');
        dlg.close();
        loadResults();
      } catch (err) {
        toast(err.message, 'error');
      }
    };
  }

  // ------------------------------------------------------------------ 通過記録

  async function loadPasses() {
    try {
      await ensurePasses();
      renderPasses();
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  function deviceName(p) {
    if (p.source === 'admin') return '管理者';
    return p.device_name || p.device_uuid.slice(0, 8);
  }

  function renderPasses() {
    const sel = $('#pass-device');
    const cur = sel.value;
    sel.innerHTML = '<option value="">すべての端末</option><option value="admin">管理者入力</option>' +
      st.devices.map((d) => '<option value="' + esc(d.device_uuid) + '">' + esc(d.name || d.device_uuid.slice(0, 8)) + '</option>').join('');
    sel.value = cur;

    const qb = $('#pass-bib').value.trim();
    const dev = sel.value;
    const showDeleted = $('#pass-deleted').checked;
    const start = st.comp.start_ms;
    const rows = st.passes.filter((p) => {
      if (!showDeleted && (p.deleted || p.admin_excluded)) return false;
      if (qb !== '' && bib(p.bib) !== qb.padStart(2, '0')) return false;
      if (dev && p.device_uuid !== dev) return false;
      return true;
    });
    $('#pass-count').textContent = rows.length + ' 件 / 全 ' + st.passes.length + ' 件';
    let html = '<thead><tr><th>通過時刻</th><th class="num">経過</th><th>ビブ</th><th>チーム</th><th>端末</th><th>状態</th><th>受信</th><th>メモ</th><th></th></tr></thead><tbody>';
    if (!rows.length) html += '<tr><td colspan="9" class="muted">記録がありません</td></tr>';
    rows.forEach((p) => {
      const team = teamByBib(p.bib);
      let state = '<span class="badge badge-ok">有効</span>';
      let action = '<button class="btn btn-small" data-act="exclude" data-id="' + p.id + '">除外</button>';
      if (p.deleted) {
        state = '<span class="badge">' + (p.source === 'admin' ? '削除' : '端末で削除') + '</span>';
        action = '';
      } else if (p.admin_excluded) {
        state = '<span class="badge badge-danger">除外</span>';
        action = '<button class="btn btn-small" data-act="restore" data-id="' + p.id + '">戻す</button>';
      }
      if (p.source === 'admin' && !p.deleted) {
        state += ' <span class="badge badge-run">管理者入力</span>';
        action = '<button class="btn btn-small btn-danger-outline" data-act="delete" data-id="' + p.id + '">削除</button>';
      }
      const muted = p.deleted || p.admin_excluded;
      html += '<tr class="' + (muted ? 'row-muted' : '') + '">' +
        '<td class="' + (muted ? 'strike' : '') + '" style="font-family:var(--mono)">' + clock(p.time_ms) + '</td>' +
        '<td class="num" style="font-family:var(--mono)">' + (start ? dur(p.time_ms - start) : '') + '</td>' +
        '<td><span class="bib">' + bib(p.bib) + '</span></td>' +
        '<td>' + (team ? esc(team.name) : '<span class="badge badge-warn">未登録</span>') + '</td>' +
        '<td>' + esc(deviceName(p)) + '</td>' +
        '<td>' + state + '</td>' +
        '<td class="small muted">' + esc((p.received_at || '').slice(11)) + '</td>' +
        '<td class="small">' + esc(p.note || '') + '</td>' +
        '<td>' + action + '</td></tr>';
    });
    $('#pass-table').innerHTML = html + '</tbody>';
  }

  $('#pass-bib').addEventListener('input', renderPasses);
  $('#pass-device').addEventListener('change', renderPasses);
  $('#pass-deleted').addEventListener('change', renderPasses);
  $('#pass-table').addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const id = Number(btn.dataset.id);
    try {
      if (btn.dataset.act === 'delete') {
        if (!confirm('この管理者入力を削除しますか？')) return;
        await api('admin.pass.delete', { competition_id: ID, id });
      } else {
        await api('admin.pass.exclude', { competition_id: ID, id, excluded: btn.dataset.act === 'exclude' });
      }
      loadPasses();
    } catch (err) {
      toast(err.message, 'error');
    }
  });
  $('#form-pass').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    try {
      await api('admin.pass.add', { competition_id: ID, bib: f.elements.bib.value, kind: f.elements.kind.value, value: f.elements.value.value, note: f.elements.note.value });
      toast('通過を追加しました');
      f.elements.bib.value = '';
      f.elements.value.value = '';
      f.elements.note.value = '';
      f.elements.bib.focus();
      loadPasses();
    } catch (err) {
      toast(err.message, 'error');
    }
  });
  $('#form-pass').elements.kind.addEventListener('change', (e) => {
    $('#form-pass').elements.value.placeholder = e.target.value === 'elapsed' ? '12:34.5' : '10:23:45.6';
  });

  // ------------------------------------------------------------------ 選手登録

  function renderTeams() {
    const n = Math.max(st.comp.team_size, ...st.teams.map((t) => t.runners.length), 1);
    const openCount = st.teams.filter((t) => t.force_open || t.runners.length < st.comp.team_size).length;
    $('#team-count').textContent = st.teams.length + ' チーム（うちオープン ' + openCount + '）/ 選手 ' + st.teams.reduce((a, t) => a + t.runners.length, 0) + ' 名';
    let html = '<thead><tr><th>ビブ</th><th>チーム名</th><th>区分</th><th class="num">人数</th><th>参加</th>';
    for (let i = 1; i <= n; i++) html += '<th>' + i + '走</th>';
    html += '<th>状態</th><th>備考</th><th></th></tr></thead><tbody>';
    if (!st.teams.length) html += '<tr><td colspan="' + (n + 8) + '" class="muted">チームが登録されていません</td></tr>';
    st.teams.forEach((t) => {
      const open = t.force_open || t.runners.length < st.comp.team_size;
      html += '<tr class="clickable" data-id="' + t.id + '"><td><span class="bib">' + bib(t.bib) + '</span></td>' +
        '<td><strong>' + esc(t.name) + '</strong></td><td>' + esc(t.category) + '</td>' +
        '<td class="num">' + t.runners.length + '</td>' +
        '<td>' + (open ? '<span class="badge badge-open">オープン</span>' : '<span class="badge badge-ok">正式</span>') + '</td>';
      for (let i = 0; i < n; i++) {
        const r = t.runners[i];
        html += '<td>' + (r ? esc(r.name) + (r.kana ? '<div class="small muted">' + esc(r.kana) + '</div>' : '') : '') + '</td>';
      }
      html += '<td>' + (t.status ? '<span class="badge badge-danger">' + esc(t.status) + '</span>' : '') + '</td>' +
        '<td class="small">' + esc(t.note) + '</td>' +
        '<td><button class="btn-link" data-act="edit" data-id="' + t.id + '">編集</button>' +
        '<button class="btn-link danger" data-act="delete" data-id="' + t.id + '">削除</button></td></tr>';
    });
    $('#team-table').innerHTML = html + '</tbody>';
    const cats = Array.from(new Set(st.teams.map((t) => t.category).filter(Boolean)));
    $('#category-list').innerHTML = cats.map((c) => '<option value="' + esc(c) + '">').join('');
  }

  $('#team-table').addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    const team = st.teams.find((t) => t.id === Number(tr.dataset.id));
    if (btn && btn.dataset.act === 'delete') {
      if (!confirm('ビブ ' + bib(team.bib) + '「' + team.name + '」を削除しますか？\n（通過記録は残ります）')) return;
      try {
        await api('admin.team.delete', { competition_id: ID, id: team.id });
        await loadComp();
        renderTeams();
        toast('削除しました');
      } catch (err) {
        toast(err.message, 'error');
      }
      return;
    }
    openTeam(team);
  });

  function runnerRow(r, i) {
    r = r || {};
    const f = (k, w) => '<td><input data-k="' + k + '" value="' + esc(r[k] || '') + '"' + (w ? ' style="min-width:' + w + 'px"' : '') + '></td>';
    return '<tr><td class="center"><b>' + (i + 1) + '</b></td>' + f('name', 120) + f('kana', 120) + f('gender') + f('age') + f('affiliation', 120) + f('note') +
      '<td><button type="button" class="btn-link danger" data-remove title="この行を削除">×</button></td></tr>';
  }
  function renumberRunners() {
    $$('#runner-rows tr').forEach((tr, i) => { tr.cells[0].innerHTML = '<b>' + (i + 1) + '</b>'; });
  }

  function openTeam(team) {
    const f = $('#form-team');
    f.reset();
    const t = team || { id: '', bib: '', name: '', category: '', status: '', force_open: 0, note: '', runners: [] };
    $('#team-dialog-title').textContent = team ? 'チームの編集' : 'チームの追加';
    L.fill(f, { id: t.id, bib: t.bib === '' ? nextBib() : bib(t.bib), name: t.name, category: t.category, status: t.status, force_open: t.force_open, note: t.note });
    const rows = Math.max(st.comp.team_size, t.runners.length);
    let html = '';
    for (let i = 0; i < rows; i++) html += runnerRow(t.runners[i], i);
    $('#runner-rows').innerHTML = html;
    $('#team-dialog').showModal();
  }
  function nextBib() {
    const used = new Set(st.teams.map((t) => t.bib));
    for (let i = 1; i < 100; i++) if (!used.has(i)) return bib(i);
    return '';
  }

  $('#btn-team-add').addEventListener('click', () => openTeam(null));
  $('#btn-runner-add').addEventListener('click', () => {
    $('#runner-rows').insertAdjacentHTML('beforeend', runnerRow({}, $$('#runner-rows tr').length));
  });
  $('#runner-rows').addEventListener('click', (e) => {
    if (e.target.closest('[data-remove]')) {
      e.target.closest('tr').remove();
      renumberRunners();
    }
  });
  $('#form-team').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const data = L.formData(f);
    data.runners = $$('#runner-rows tr').map((tr) => {
      const r = {};
      $$('input[data-k]', tr).forEach((inp) => { r[inp.dataset.k] = inp.value; });
      return r;
    });
    try {
      await api('admin.team.save', { competition_id: ID, team: data });
      $('#team-dialog').close();
      await loadComp();
      renderTeams();
      toast('保存しました');
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  // 取り込み
  $('#btn-import-toggle').addEventListener('click', () => {
    $('#import-panel').hidden = !$('#import-panel').hidden;
  });
  $('#import-file').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const buf = await file.arrayBuffer();
    let text;
    try {
      text = new TextDecoder('utf-8', { fatal: true }).decode(buf);
    } catch (_) {
      text = new TextDecoder('shift_jis').decode(buf); // Excel の CSV (Shift_JIS)
    }
    $('#import-text').value = text;
    preview();
  });
  $('#import-text').addEventListener('input', () => { $('#btn-import-run').disabled = true; });
  $('#btn-import-preview').addEventListener('click', preview);

  async function preview() {
    try {
      const res = await api('admin.roster.preview', { competition_id: ID, text: $('#import-text').value });
      const n = Math.max(st.comp.team_size, ...res.teams.map((t) => t.runners.length), 1);
      let html = res.warnings.map((w) => '<p class="alert alert-warn small">' + esc(w) + '</p>').join('');
      html += '<p><strong>' + res.teams.length + ' チーム / ' + res.teams.reduce((a, t) => a + t.runners.length, 0) + ' 名</strong> を取り込みます。</p>';
      html += '<div class="scroll-x"><table class="table"><thead><tr><th>ビブ</th><th>チーム名</th><th>区分</th><th>参加</th>';
      for (let i = 1; i <= n; i++) html += '<th>' + i + '走</th>';
      html += '</tr></thead><tbody>';
      res.teams.forEach((t) => {
        const exists = teamByBib(t.bib);
        const open = t.force_open || t.runners.length < st.comp.team_size;
        html += '<tr><td><span class="bib">' + bib(t.bib) + '</span>' + (exists ? ' <span class="badge badge-warn">上書き</span>' : '') + '</td><td>' + esc(t.name) + '</td><td>' + esc(t.category) + '</td>' +
          '<td>' + (open ? '<span class="badge badge-open">オープン</span>' : '<span class="badge badge-ok">正式</span>') + '</td>';
        for (let i = 0; i < n; i++) html += '<td>' + (t.runners[i] ? esc(t.runners[i].name) : '') + '</td>';
        html += '</tr>';
      });
      $('#import-preview').innerHTML = html + '</tbody></table></div>';
      $('#btn-import-run').disabled = res.teams.length === 0;
    } catch (err) {
      $('#import-preview').innerHTML = '<p class="alert alert-error">' + esc(err.message) + '</p>';
      $('#btn-import-run').disabled = true;
    }
  }

  $('#btn-import-run').addEventListener('click', async () => {
    const mode = $('#import-mode').value;
    if (mode === 'replace' && !confirm('登録済みのチームをすべて削除して置き換えます。よろしいですか？')) return;
    try {
      const res = await api('admin.roster.import', { competition_id: ID, text: $('#import-text').value, mode });
      toast('取り込みました（新規 ' + res.created + ' / 更新 ' + res.updated + '）');
      $('#import-text').value = '';
      $('#import-preview').innerHTML = '';
      $('#import-panel').hidden = true;
      $('#btn-import-run').disabled = true;
      await loadComp();
      renderTeams();
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  // ------------------------------------------------------------------ 計測端末

  async function loadDevices() {
    const url = new URL('../m/#/c/' + ID, location.href).href;
    $('#device-url').textContent = url;
    try {
      await ensurePasses();
    } catch (err) {
      toast(err.message, 'error');
      return;
    }
    let html = '<thead><tr><th>端末名</th><th class="num">記録</th><th class="num">削除</th><th class="num">時刻差</th><th class="num">往復遅延</th><th>初回接続</th><th>最終通信</th><th>ブラウザ</th><th></th></tr></thead><tbody>';
    if (!st.devices.length) html += '<tr><td colspan="9" class="muted">まだ端末が接続していません</td></tr>';
    st.devices.forEach((d) => {
      html += '<tr><td><strong>' + esc(d.name || '（名前なし）') + '</strong><div class="small muted">' + esc(d.device_uuid.slice(0, 8)) + '</div></td>' +
        '<td class="num">' + d.pass_count + '</td><td class="num">' + d.deleted_count + '</td>' +
        '<td class="num">' + (d.clock_offset_ms == null ? '' : (d.clock_offset_ms / 1000).toFixed(2) + ' 秒') + '</td>' +
        '<td class="num">' + (d.clock_rtt_ms == null ? '' : d.clock_rtt_ms + ' ms') + '</td>' +
        '<td class="small">' + esc(d.first_seen_at) + '</td><td class="small">' + esc(d.last_seen_at) + '</td>' +
        '<td class="small muted" style="max-width:260px;white-space:normal">' + esc(d.user_agent) + '</td>' +
        '<td><button class="btn-link" data-id="' + d.id + '" data-name="' + esc(d.name) + '">名前変更</button></td></tr>';
    });
    $('#device-table').innerHTML = html + '</tbody>';
  }
  $('#device-table').addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-id]');
    if (!btn) return;
    const name = prompt('端末名', btn.dataset.name);
    if (name == null) return;
    try {
      await api('admin.device.rename', { competition_id: ID, id: Number(btn.dataset.id), name });
      loadDevices();
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  // ------------------------------------------------------------------ 大会設定

  function renderSettings() {
    const c = st.comp;
    L.fill($('#form-comp'), {
      name: c.name, event_date: c.event_date, location: c.location, status: c.status, team_size: c.team_size,
      device_code: c.device_code, adopt_method: c.adopt_method,
      merge_window_s: c.merge_window_ms / 1000, tolerance_s: c.tolerance_ms / 1000, note: c.note,
    });
    renderHead();
  }

  $('#form-comp').addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = L.formData(e.target);
    d.id = ID;
    d.merge_window_ms = Math.round(Number(d.merge_window_s) * 1000);
    d.tolerance_ms = Math.round(Number(d.tolerance_s) * 1000);
    try {
      await api('admin.competition.save', d);
      await loadComp();
      renderSettings();
      toast('保存しました');
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  async function setStart(mode, value) {
    try {
      await api('admin.start.set', { id: ID, mode, value });
      await loadComp();
      renderSettings();
      toast(mode === 'reset' ? '計測をリセットしました。計測端末で「計測スタート」を押せます' : 'スタート時刻を設定しました');
      if (mode === 'reset') {
        st.results = null;
        st.passes = null;
      }
    } catch (err) {
      toast(err.message, 'error');
    }
  }
  $('#btn-start-now').addEventListener('click', () => {
    const msg = st.comp.start_ms ? '現在のスタート時刻（' + clock(st.comp.start_ms) + '）を今の時刻で上書きします。よろしいですか？' : '今の時刻をスタートとして記録します。';
    if (confirm(msg)) setStart('now');
  });
  $('#btn-run-reset').addEventListener('click', () => {
    if (confirm('計測をリセットしますか？\n\nスタート時刻を未記録に戻し、これまでの通過記録を集計対象から外します（記録は保存されます）。\n計測端末の記録もリセットされます。')) setStart('reset');
  });
  $('#form-start').addEventListener('submit', (e) => {
    e.preventDefault();
    setStart('clock', e.target.elements.value.value);
  });
  $('#form-delete').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!confirm('本当に削除しますか？元に戻せません。')) return;
    try {
      await api('admin.competition.delete', { id: ID, confirm: e.target.elements.confirm.value });
      location.href = 'index.php';
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  // ------------------------------------------------------------------ ポーリング

  let pollCount = 0;
  function poll() {
    if (document.hidden) return;
    pollCount++;
    if (st.tab === 'results' && $('#res-auto').checked) {
      loadResults();
      return;
    }
    if (st.tab === 'passes') loadPasses();
    // 集計タブ以外でもヘッダのタイマー（全走者ゴールで停止）を更新する
    if (pollCount % 2 === 0) {
      api('admin.competition', { id: ID }).then((res) => {
        st.comp = res.competition;
        st.race = res.race;
        renderHead();
      }).catch(() => {});
    }
  }

  (async function init() {
    try {
      await loadComp();
    } catch (err) {
      toast(err.message, 'error');
      return;
    }
    showTab(location.hash.slice(1) || (st.comp.start_ms ? 'results' : (st.teams.length ? 'results' : 'teams')));
    window.addEventListener('hashchange', () => {
      const name = location.hash.slice(1);
      if (name && name !== st.tab) showTab(name);
    });
    setInterval(tickElapsed, 100);
    setInterval(poll, 3000);
    document.addEventListener('visibilitychange', poll);
  })();
})();
