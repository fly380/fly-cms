<?php
/**
 * plugins/lang-translator/settings.php
 * Підключається через /admin/plugin-settings.php?plugin=lang-translator
 * $pdo і $csrf вже доступні з plugin-settings.php
 */

// ── Збереження ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['csrf'] ?? '') === $csrf) {

    $allowed_langs = ['en', 'pl', 'de', 'fr', 'es', 'it', 'cs', 'sk', 'ro', 'hu'];
    $chosen = array_intersect($_POST['languages'] ?? [], $allowed_langs);

    $fields = [
        'lt_languages'   => implode(',', $chosen) ?: 'en',
        'lt_position'    => in_array($_POST['position'] ?? '', ['navbar','footer','floating']) ? $_POST['position'] : 'navbar',
        'lt_style'       => in_array($_POST['style'] ?? '', ['flag_name','flag','dropdown']) ? $_POST['style'] : 'flag_name',
        'lt_cache_hours' => max(1, min(168, (int)($_POST['cache_hours'] ?? 24))),
    ];

    foreach ($fields as $key => $val) {
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")
            ->execute([$key, $val]);
    }
    // Redirect щоб уникнути повторного POST і оновити CSRF токен
    header('Location: /admin/plugin-settings.php?plugin=lang-translator&saved=1');
    exit;
    $settingsFlash = ['type' => 'success', 'msg' => '🌐 Налаштування перекладача збережено'];
}

// ── Поточні значення ──────────────────────────────────────────────
if (!function_exists('lt_s')) {
    function lt_s(PDO $pdo, string $key, string $def = ''): string {
        try {
            $v = $pdo->prepare("SELECT value FROM settings WHERE key=? LIMIT 1");
            $v->execute(['lt_' . $key]);
            $r = $v->fetchColumn();
            return ($r !== false && $r !== null) ? $r : $def;
        } catch (Exception $e) { return $def; }
    }
}

$cur_langs    = array_filter(array_map('trim', explode(',', lt_s($pdo, 'languages', 'en,pl,de'))));
$cur_position = lt_s($pdo, 'position',    'navbar');
$cur_style    = lt_s($pdo, 'style',       'flag_name');
$cur_cache    = lt_s($pdo, 'cache_hours', '24');

$all_langs = [
    'en' => ['flag' => '🇬🇧', 'name' => 'English'],
    'pl' => ['flag' => '🇵🇱', 'name' => 'Polski'],
    'de' => ['flag' => '🇩🇪', 'name' => 'Deutsch'],
    'fr' => ['flag' => '🇫🇷', 'name' => 'Français'],
    'es' => ['flag' => '🇪🇸', 'name' => 'Español'],
    'it' => ['flag' => '🇮🇹', 'name' => 'Italiano'],
    'cs' => ['flag' => '🇨🇿', 'name' => 'Čeština'],
    'sk' => ['flag' => '🇸🇰', 'name' => 'Slovenčina'],
    'ro' => ['flag' => '🇷🇴', 'name' => 'Română'],
    'hu' => ['flag' => '🇭🇺', 'name' => 'Magyar'],
];
?>

<div style="max-width:600px">

  <!-- Мови -->
  <div class="mb-4">
    <label class="form-label fw-semibold">🌍 Мови перекладу</label>
    <div class="form-text small mb-2">Українська (UA) — завжди активна як вихідна мова</div>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($all_langs as $code => $lang): ?>
      <div class="form-check form-check-inline border rounded px-3 py-2"
           style="cursor:pointer;min-width:130px"
           onclick="this.querySelector('input').click()">
        <input class="form-check-input" type="checkbox"
               name="languages[]" value="<?= $code ?>" id="lang_<?= $code ?>"
               <?= in_array($code, $cur_langs) ? 'checked' : '' ?>>
        <label class="form-check-label" for="lang_<?= $code ?>" style="cursor:pointer">
          <?= $lang['flag'] ?> <?= $lang['name'] ?>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Позиція -->
  <div class="mb-4">
    <label class="form-label fw-semibold">📍 Позиція switcher</label>
    <div class="d-flex gap-3 flex-wrap">
      <?php foreach ([
        'navbar'   => ['icon' => '🔝', 'label' => 'Navbar', 'desc' => 'У навігаційному меню (потрібен пункт типу language_switcher)'],
        'footer'   => ['icon' => '🔻', 'label' => 'Footer',  'desc' => 'Під footer сайту'],
        'floating' => ['icon' => '📌', 'label' => 'Floating','desc' => 'Фіксована кнопка внизу праворуч'],
      ] as $val => $opt): ?>
      <div class="form-check border rounded p-3" style="min-width:160px;cursor:pointer"
           onclick="this.querySelector('input').click()">
        <input class="form-check-input" type="radio" name="position"
               id="pos_<?= $val ?>" value="<?= $val ?>"
               <?= $cur_position === $val ? 'checked' : '' ?>>
        <label class="form-check-label d-block" for="pos_<?= $val ?>" style="cursor:pointer">
          <div class="fw-semibold"><?= $opt['icon'] ?> <?= $opt['label'] ?></div>
          <div class="text-muted" style="font-size:.78rem"><?= $opt['desc'] ?></div>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="form-text small mt-2">
      💡 Для <strong>Navbar</strong>: додайте пункт меню типу <code>language_switcher</code> через
      <a href="/admin/menu_editor.php">Редактор меню</a>
    </div>
  </div>

  <!-- Стиль -->
  <div class="mb-4">
    <label class="form-label fw-semibold">🎨 Вигляд кнопки</label>
    <div class="d-flex gap-3 flex-wrap">
      <?php foreach ([
        'flag_name' => ['label' => '🇺🇦 UA ▼',   'desc' => 'Прапор + код мови'],
        'flag'      => ['label' => '🇺🇦 ▼',       'desc' => 'Тільки прапор'],
        'dropdown'  => ['label' => 'Мова ▼',       'desc' => 'Текстовий dropdown'],
      ] as $val => $opt): ?>
      <div class="form-check border rounded p-3" style="min-width:140px;cursor:pointer"
           onclick="this.querySelector('input').click()">
        <input class="form-check-input" type="radio" name="style"
               id="style_<?= $val ?>" value="<?= $val ?>"
               <?= $cur_style === $val ? 'checked' : '' ?>>
        <label class="form-check-label d-block" for="style_<?= $val ?>" style="cursor:pointer">
          <div class="fw-semibold"><?= $opt['label'] ?></div>
          <div class="text-muted" style="font-size:.78rem"><?= $opt['desc'] ?></div>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Кеш -->
  <div class="mb-4">
    <label class="form-label fw-semibold" for="cache_hours">⏱ Кеш перекладу (години)</label>
    <div class="d-flex align-items-center gap-3" style="max-width:280px">
      <input type="range" class="form-range flex-grow-1" id="cache_hours" name="cache_hours"
             min="1" max="168" value="<?= (int)$cur_cache ?>"
             oninput="document.getElementById('cache_val').textContent=this.value">
      <span id="cache_val" class="fw-semibold text-primary" style="min-width:36px">
        <?= (int)$cur_cache ?>
      </span>
      <span class="text-muted small">год</span>
    </div>
    <div class="form-text small">Переклад зберігається у localStorage браузера. 24 год — оптимально.</div>
  </div>

  <!-- Підказка про translate.php -->
  <div class="alert alert-info small py-2 px-3 mb-3" style="font-size:.82rem">
    <strong>⚙ Серверна частина:</strong> <code>templates/translate.php</code> — проксі до Google Translate.<br>
    Rate limit: 20 запитів / 60 сек з однієї IP. Fallback: MyMemory API.<br>
    Файл не потрібно редагувати — плагін передає конфіг через <code>window.LT_CONFIG</code>.
  </div>

</div>