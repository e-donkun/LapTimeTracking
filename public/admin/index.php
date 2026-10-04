<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$user = Auth::requirePage();
View::header('大会一覧', $user);
?>
<div class="page-head">
  <h1>大会一覧</h1>
  <div class="form-actions">
    <button class="btn" id="btn-sample" title="動作確認・操作練習用に、選手登録済みのサンプル大会を追加します">サンプル大会を追加</button>
    <button class="btn btn-primary" id="btn-new">＋ 新しい大会</button>
  </div>
</div>

<section class="card" id="new-form" hidden>
  <h2>新しい大会</h2>
  <form id="form-new" class="grid-form">
    <label class="span-2">試合名<input name="name" required maxlength="100"></label>
    <label>日程<input type="date" name="event_date"></label>
    <label>場所<input name="location" maxlength="100"></label>
    <label>正式参加の人数<input type="number" name="team_size" value="3" min="1" max="10"></label>
    <div class="form-actions span-2">
      <button class="btn btn-primary" type="submit">作成</button>
      <button class="btn" type="button" id="btn-cancel">キャンセル</button>
    </div>
  </form>
</section>

<section class="card">
  <table class="table" id="comp-table">
    <thead>
      <tr><th>試合名</th><th>日程</th><th>場所</th><th>状態</th><th class="num">チーム</th><th class="num">通過記録</th><th>スタート</th></tr>
    </thead>
    <tbody><tr><td colspan="7" class="muted">読み込み中…</td></tr></tbody>
  </table>
</section>
<?php View::footer(['admin-index.js']); ?>
