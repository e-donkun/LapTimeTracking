<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$user = Auth::requirePage();
$comp = Repo::competition((int) ($_GET['id'] ?? 0));
if (!$comp) {
    header('Location: index.php');
    exit;
}
View::header($comp['name'], $user);
?>
<div id="app" data-id="<?= (int) $comp['id'] ?>">
  <div class="comp-head">
    <div>
      <a href="index.php" class="muted small">← 大会一覧</a>
      <h1 id="comp-name"><?= Util::h($comp['name']) ?></h1>
      <div class="muted" id="comp-meta"></div>
    </div>
    <div class="comp-clock">
      <div class="small muted">スタート <span id="start-label">-</span></div>
      <div class="elapsed" id="elapsed">--:--.-</div>
    </div>
    <div class="comp-actions">
      <a class="btn btn-primary" href="export.php?id=<?= (int) $comp['id'] ?>">Excel 出力</a>
    </div>
  </div>

  <nav class="tabs" role="tablist">
    <button role="tab" data-tab="results" class="active">速報・集計</button>
    <button role="tab" data-tab="passes">通過記録</button>
    <button role="tab" data-tab="teams">選手登録</button>
    <button role="tab" data-tab="devices">計測端末</button>
    <button role="tab" data-tab="settings">大会設定</button>
  </nav>

  <!-- 速報・集計 -->
  <section class="tab-panel" data-panel="results">
    <div class="toolbar">
      <div class="chips" id="summary"></div>
      <div class="toolbar-right">
        <input type="search" id="res-filter" placeholder="ビブ・チーム・氏名で絞り込み">
        <label class="check"><input type="checkbox" id="res-flagged"> 要確認のみ</label>
        <label class="check"><input type="checkbox" id="res-auto" checked> 自動更新</label>
        <span class="small muted" id="res-updated"></span>
      </div>
    </div>
    <div class="card no-pad scroll-x">
      <table class="table results" id="res-table"></table>
    </div>
    <div id="res-extra"></div>
    <p class="small muted legend">
      各区間: <b>ラップ</b>（区間順位）/ 通過時点の累計。
      <span class="flag-dot"></span> は端末間の差・記録漏れなど確認が必要な通過です。セルをクリックすると端末ごとの記録を確認できます。
    </p>
  </section>

  <!-- 通過記録 -->
  <section class="tab-panel" data-panel="passes" hidden>
    <div class="card">
      <h2>手動で通過を追加</h2>
      <p class="small muted">端末の記録が無い・誤っている場合に使います。同じ通過に端末の記録があっても<strong>管理者入力が優先</strong>されます。</p>
      <form id="form-pass" class="inline-form">
        <label>ビブ<input name="bib" inputmode="numeric" maxlength="2" pattern="\d{1,2}" required class="w-bib"></label>
        <label>入力方法
          <select name="kind"><option value="clock">通過時刻 (HH:MM:SS.s)</option><option value="elapsed">スタートからの経過 (M:SS.s)</option></select>
        </label>
        <label>値<input name="value" required placeholder="10:23:45.6"></label>
        <label>メモ<input name="note" maxlength="100"></label>
        <button class="btn btn-primary" type="submit">追加</button>
      </form>
    </div>
    <div class="toolbar">
      <div class="toolbar-left">
        <input type="search" id="pass-bib" placeholder="ビブ" class="w-bib" inputmode="numeric">
        <select id="pass-device"><option value="">すべての端末</option></select>
        <label class="check"><input type="checkbox" id="pass-deleted"> 削除・除外も表示</label>
      </div>
      <span class="small muted" id="pass-count"></span>
    </div>
    <div class="card no-pad scroll-x">
      <table class="table" id="pass-table"></table>
    </div>
  </section>

  <!-- 選手登録 -->
  <section class="tab-panel" data-panel="teams" hidden>
    <div class="toolbar">
      <div class="toolbar-left">
        <button class="btn btn-primary" id="btn-team-add">＋ チーム追加</button>
        <button class="btn" id="btn-import-toggle">CSV / Excel から取り込み</button>
        <a class="btn" href="export.php?type=template">テンプレート (CSV)</a>
      </div>
      <span class="small muted" id="team-count"></span>
    </div>
    <div class="card" id="import-panel" hidden>
      <h2>名簿の取り込み</h2>
      <p class="small muted">
        1行＝1選手。見出し行に「ビブ」「氏名」が必要です（他に チーム名・区分・走順・フリガナ・性別・学年・年齢・所属・備考・オープン に対応）。
        チーム列が空の行は直前のチームを引き継ぐため、Excel の表をそのまま<strong>コピー＆貼り付け</strong>できます。
      </p>
      <div class="import-grid">
        <textarea id="import-text" rows="8" placeholder="ここに Excel からコピーした表、または CSV を貼り付け"></textarea>
        <div>
          <label>ファイルを選択 (CSV / TSV)<input type="file" id="import-file" accept=".csv,.tsv,.txt,text/csv"></label>
          <label>取り込み方法
            <select id="import-mode">
              <option value="merge">同じビブは上書き・他は残す</option>
              <option value="replace">全チームを削除して置き換え</option>
            </select>
          </label>
          <div class="form-actions">
            <button class="btn" id="btn-import-preview">プレビュー</button>
            <button class="btn btn-primary" id="btn-import-run" disabled>取り込む</button>
          </div>
        </div>
      </div>
      <div id="import-preview"></div>
    </div>
    <div class="card no-pad scroll-x">
      <table class="table" id="team-table"></table>
    </div>
  </section>

  <!-- 計測端末 -->
  <section class="tab-panel" data-panel="devices" hidden>
    <div class="card">
      <h2>計測端末の URL</h2>
      <p>スマートフォンで <code id="device-url"></code> を開き、この大会を選んでください。</p>
      <p class="small muted">端末はサーバとの時刻差を自動で測定し、通過時刻をサーバ時刻に揃えて記録します。オフライン中の記録は端末内に保存され、通信回復後に自動で送信されます。</p>
    </div>
    <div class="card no-pad scroll-x">
      <table class="table" id="device-table"></table>
    </div>
  </section>

  <!-- 大会設定 -->
  <section class="tab-panel" data-panel="settings" hidden>
    <div class="settings-grid">
      <div class="card">
        <h2>大会情報</h2>
        <form id="form-comp" class="grid-form">
          <label class="span-2">試合名<input name="name" required maxlength="100"></label>
          <label>日程<input type="date" name="event_date"></label>
          <label>場所<input name="location" maxlength="100"></label>
          <label>状態
            <select name="status"><option value="preparing">準備中</option><option value="active">開催中</option><option value="finished">終了</option></select>
          </label>
          <label>正式参加の人数<input type="number" name="team_size" min="1" max="10"></label>
          <label>計測パスコード<input name="device_code" maxlength="32" placeholder="空欄なら不要" autocomplete="off"></label>
          <label>採用方法
            <select name="adopt_method"><option value="median">端末の中央値</option><option value="earliest">最も早い端末</option></select>
          </label>
          <label>同一通過とみなす時間（秒）<input type="number" name="merge_window_s" min="1" max="600" step="1"></label>
          <label>端末間の許容差（秒）<input type="number" name="tolerance_s" min="0" max="60" step="0.1"></label>
          <label class="span-2">備考<textarea name="note" rows="2"></textarea></label>
          <div class="form-actions span-2"><button class="btn btn-primary" type="submit">保存</button></div>
        </form>
      </div>
      <div>
        <div class="card">
          <h2>スタート時刻</h2>
          <p class="start-now" id="start-detail">未記録</p>
          <p class="small muted">計測端末の「計測スタート」を最初に押した時点のサーバ時刻が記録されます。ここで修正もできます。</p>
          <div class="form-actions">
            <button class="btn btn-primary" id="btn-start-now">今の時刻でスタート</button>
            <button class="btn btn-danger-outline" id="btn-start-clear">クリア</button>
          </div>
          <form id="form-start" class="inline-form">
            <label>時刻を指定<input name="value" placeholder="10:00:00.0" required></label>
            <button class="btn" type="submit">設定</button>
          </form>
        </div>
        <div class="card danger-zone">
          <h2>大会の削除</h2>
          <p class="small">選手・通過記録もすべて削除されます。元に戻せません。</p>
          <form id="form-delete" class="inline-form">
            <label>確認のため大会名を入力<input name="confirm" autocomplete="off"></label>
            <button class="btn btn-danger" type="submit">削除</button>
          </form>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- チーム編集 -->
<dialog id="team-dialog" class="dialog">
  <form id="form-team" method="dialog">
    <h2 id="team-dialog-title">チーム</h2>
    <input type="hidden" name="id">
    <div class="grid-form">
      <label>ビブ（00〜99）<input name="bib" inputmode="numeric" maxlength="2" pattern="\d{1,2}" required></label>
      <label>チーム名<input name="name" maxlength="100"></label>
      <label>区分<input name="category" maxlength="50" list="category-list"></label>
      <label>状態
        <select name="status"><option value="">（通常）</option><option value="DNS">DNS（欠場）</option><option value="DNF">DNF（途中棄権）</option><option value="DQ">DQ（失格）</option></select>
      </label>
      <label class="check span-2"><input type="checkbox" name="force_open"> 人数に関わらずオープン参加にする</label>
      <label class="span-2">備考<input name="note" maxlength="200"></label>
    </div>
    <h3>走者 <span class="small muted">（上から 1走・2走・3走… 氏名が空の行は無視）</span></h3>
    <div class="scroll-x">
      <table class="table runner-table">
        <thead><tr><th>走順</th><th>氏名</th><th>フリガナ</th><th>性別</th><th>学年・年齢</th><th>所属</th><th>備考</th><th></th></tr></thead>
        <tbody id="runner-rows"></tbody>
      </table>
    </div>
    <button class="btn btn-small" type="button" id="btn-runner-add">＋ 走者を追加</button>
    <div class="dialog-actions">
      <button class="btn" type="button" data-close>キャンセル</button>
      <button class="btn btn-primary" type="submit" value="save">保存</button>
    </div>
  </form>
</dialog>
<datalist id="category-list"></datalist>

<!-- 通過の詳細（端末比較） -->
<dialog id="crossing-dialog" class="dialog">
  <div id="crossing-body"></div>
  <div class="dialog-actions"><button class="btn" type="button" data-close>閉じる</button></div>
</dialog>
<?php View::footer(['admin-competition.js']); ?>
