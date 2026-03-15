<?php
/**
 * plugins/seo-meta/settings.php
 * Підключається через /admin/plugin-settings.php?plugin=seo-meta
 */

// $pdo і $csrf вже доступні з plugin-settings.php

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['csrf'] ?? '') === $csrf) {
    $fields = [
        'seo_meta_default_image'  => trim($_POST['default_image']  ?? ''),
        'seo_meta_twitter_site'   => trim($_POST['twitter_site']   ?? ''),
        'seo_meta_twitter_card'   => in_array($_POST['twitter_card'] ?? '', ['summary','summary_large_image']) ? $_POST['twitter_card'] : 'summary_large_image',
        'seo_meta_schema_article' => isset($_POST['schema_article']) ? '1' : '0',
    ];
    foreach ($fields as $key => $val) {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE key=?")->execute([$key]) ?
            $pdo->query("SELECT COUNT(*) FROM settings WHERE key='$key'")->fetchColumn() : 0;
        if ($exists) {
            $pdo->prepare("UPDATE settings SET value=? WHERE key=?")->execute([$val, $key]);
        } else {
            $pdo->prepare("INSERT INTO settings(key,value) VALUES(?,?)")->execute([$key, $val]);
        }
    }
    $settingsFlash = ['type'=>'success','msg'=>'Налаштування SEO збережено'];
}

function seo_s(PDO $pdo, string $key, string $def = ''): string {
    try {
        $v = $pdo->prepare("SELECT value FROM settings WHERE key=? LIMIT 1");
        $v->execute(['seo_meta_' . $key]);
        $r = $v->fetchColumn();
        return $r !== false ? $r : $def;
    } catch (Exception $e) { return $def; }
}
?>
<div style="max-width:560px">
  <div class="mb-3">
    <label class="form-label small fw-semibold">Зображення за замовчуванням (OG Image)</label>
    <input type="text" class="form-control" name="default_image"
      value="<?= htmlspecialchars(seo_s($pdo,'default_image','')) ?>"
      placeholder="https://your-site.com/assets/images/og-default.jpg">
    <div class="form-text small">Показується якщо у пості немає thumbnail</div>
  </div>
  <div class="mb-3">
    <label class="form-label small fw-semibold">Twitter @site</label>
    <input type="text" class="form-control" name="twitter_site"
      value="<?= htmlspecialchars(seo_s($pdo,'twitter_site','')) ?>"
      placeholder="@yourhandle">
  </div>
  <div class="mb-3">
    <label class="form-label small fw-semibold">Twitter Card тип</label>
    <select class="form-select" name="twitter_card">
      <option value="summary_large_image" <?= seo_s($pdo,'twitter_card','summary_large_image')==='summary_large_image'?'selected':'' ?>>summary_large_image (велике фото)</option>
      <option value="summary"             <?= seo_s($pdo,'twitter_card')==='summary'?'selected':'' ?>>summary (маленьке фото)</option>
    </select>
  </div>
  <div class="mb-3 form-check">
    <input type="checkbox" class="form-check-input" name="schema_article" id="schemaArt"
      <?= seo_s($pdo,'schema_article','1')==='1'?'checked':'' ?>>
    <label class="form-check-label small" for="schemaArt">
      Додавати schema.org Article розмітку до постів
    </label>
  </div>
</div>
