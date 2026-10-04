/* 管理画面: 大会一覧 */
(function () {
  'use strict';
  const { api, esc, toast, formData } = window.LTT;
  const STATUS = { preparing: ['準備中', ''], active: ['開催中', 'badge-run'], finished: ['終了', 'badge-ok'] };

  const formWrap = document.getElementById('new-form');
  const form = document.getElementById('form-new');

  document.getElementById('btn-new').addEventListener('click', () => {
    formWrap.hidden = false;
    form.elements.name.focus();
  });
  document.getElementById('btn-cancel').addEventListener('click', () => { formWrap.hidden = true; });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const res = await api('admin.competition.save', formData(form));
      location.href = 'competition.php?id=' + res.id + '#teams';
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  async function load() {
    const tbody = document.querySelector('#comp-table tbody');
    try {
      const res = await api('admin.competitions');
      if (!res.competitions.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="muted">大会がまだありません。「新しい大会」から作成してください。</td></tr>';
        return;
      }
      tbody.innerHTML = res.competitions.map((c) => {
        const st = STATUS[c.status] || [c.status, ''];
        return '<tr class="clickable" data-id="' + c.id + '">' +
          '<td><a href="competition.php?id=' + c.id + '"><strong>' + esc(c.name) + '</strong></a></td>' +
          '<td>' + esc(c.event_date || '') + '</td>' +
          '<td>' + esc(c.location || '') + '</td>' +
          '<td><span class="badge ' + st[1] + '">' + st[0] + '</span></td>' +
          '<td class="num">' + c.team_count + '</td>' +
          '<td class="num">' + c.pass_count + '</td>' +
          '<td>' + (c.start_ms ? window.LTT.clock(c.start_ms) : '<span class="muted">未</span>') + '</td>' +
          '</tr>';
      }).join('');
    } catch (err) {
      tbody.innerHTML = '<tr><td colspan="7" class="alert-error">' + esc(err.message) + '</td></tr>';
    }
  }

  document.querySelector('#comp-table tbody').addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr && !e.target.closest('a')) location.href = 'competition.php?id=' + tr.dataset.id;
  });

  load();
})();
