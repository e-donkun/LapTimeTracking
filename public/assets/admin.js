/* 管理画面 共通 */
(function () {
  'use strict';

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');

  const LTT = {
    csrf: csrfMeta ? csrfMeta.content : '',
    serverOffset: 0, // サーバ時刻 - ブラウザ時刻

    /** 読み取り系ルートは GET、それ以外は POST(JSON) */
    GET_ROUTES: ['time', 'competitions', 'competition', 'results', 'admin.competitions', 'admin.competition', 'admin.passes'],

    async api(route, data, method) {
      method = method || (LTT.GET_ROUTES.includes(route) ? 'GET' : 'POST');
      let url = '../api/index.php?r=' + encodeURIComponent(route);
      const opts = { method, headers: { 'X-CSRF-Token': LTT.csrf }, credentials: 'same-origin' };
      if (method === 'GET' && data) {
        url += '&' + new URLSearchParams(data).toString();
      } else if (method === 'POST') {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(data || {});
      }
      const t0 = Date.now();
      const res = await fetch(url, opts);
      let json;
      try {
        json = await res.json();
      } catch (e) {
        throw new Error('サーバの応答が不正です (' + res.status + ')');
      }
      if (json && typeof json.server_ms === 'number') {
        LTT.serverOffset = json.server_ms - (t0 + Date.now()) / 2;
      }
      if (!json.ok) {
        if (json.code === 'login_required') {
          location.href = 'login.php?back=' + encodeURIComponent(location.pathname + location.search);
        }
        throw new Error(json.error || 'エラーが発生しました');
      }
      return json;
    },

    serverNow() {
      return Date.now() + LTT.serverOffset;
    },

    esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    },

    bib(n) {
      return String(n).padStart(2, '0');
    },

    /** ミリ秒 → M:SS.s / H:MM:SS.s */
    dur(ms) {
      if (ms == null) return '';
      const neg = ms < 0;
      ms = Math.abs(ms);
      const t = Math.floor((ms % 1000) / 100);
      const s = Math.floor(ms / 1000);
      const h = Math.floor(s / 3600);
      const m = Math.floor((s % 3600) / 60);
      const ss = String(s % 60).padStart(2, '0');
      const body = h > 0 ? h + ':' + String(m).padStart(2, '0') + ':' + ss + '.' + t : m + ':' + ss + '.' + t;
      return (neg ? '-' : '') + body;
    },

    /** エポックミリ秒 → HH:MM:SS.s */
    clock(ms) {
      if (ms == null) return '';
      const d = new Date(ms);
      const p = (n) => String(n).padStart(2, '0');
      return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()) + '.' + Math.floor(d.getMilliseconds() / 100);
    },

    toast(msg, type) {
      const el = document.getElementById('toast');
      if (!el) return alert(msg);
      el.textContent = msg;
      el.className = 'toast show' + (type === 'error' ? ' error' : '');
      clearTimeout(LTT._toastTimer);
      LTT._toastTimer = setTimeout(() => { el.className = 'toast'; }, type === 'error' ? 5000 : 2500);
    },

    formData(form) {
      const out = {};
      new FormData(form).forEach((v, k) => { out[k] = v; });
      form.querySelectorAll('input[type=checkbox][name]').forEach((cb) => { out[cb.name] = cb.checked; });
      return out;
    },

    fill(form, data) {
      Object.keys(data).forEach((k) => {
        const el = form.elements[k];
        if (!el) return;
        if (el.type === 'checkbox') el.checked = !!Number(data[k]) || data[k] === true;
        else el.value = data[k] == null ? '' : data[k];
      });
    },
  };

  // dialog の閉じるボタン
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-close]');
    if (btn) btn.closest('dialog').close();
  });

  window.LTT = LTT;
})();
