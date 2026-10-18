<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stats.php';

$user = auth_require();
stats_log_visit('/');

$cats = db_all(
    "SELECT id, parent_id, name, icon, image_url, color, nsfw, locked, lock_hash,
            protected_delete, position
     FROM categories WHERE user_id = ? ORDER BY position, created_at",
    [$user['id']]
);

$links = db_all(
    "SELECT l.id, l.category_id, l.url, l.title, l.tags, l.rating, l.status, l.note,
            l.wayback_url, l.due_date, l.click_count, l.last_clicked_at, l.meta, l.position,
            ls.status AS check_status
     FROM links l
     LEFT JOIN link_statuses ls ON ls.link_id = l.id
     JOIN categories c ON c.id = l.category_id
     WHERE c.user_id = ?
     ORDER BY l.position, l.created_at",
    [$user['id']]
);

$settingsRow = db_row("SELECT settings FROM user_settings WHERE user_id = ?", [$user['id']]);
$settings = $settingsRow ? json_decode($settingsRow['settings'], true) : [];

$collections = db_all(
    "SELECT id, name, icon, color, match_mode, rules FROM collections WHERE user_id = ? ORDER BY created_at",
    [$user['id']]
);

// ═══════════════════════════════════════════════════════════
// FIX: JSON-поля из MySQL → массивы для JS
//      + защита от due_date = '0000-00-00'
// ═══════════════════════════════════════════════════════════
$byId = [];
foreach ($cats as $c) {
    $c['links']            = [];
    $c['children']         = [];
    $c['nsfw']             = (int)($c['nsfw'] ?? 0);
    $c['locked']           = (int)($c['locked'] ?? 0);
    $c['protected_delete'] = (int)($c['protected_delete'] ?? 0);
    $c['position']         = (int)($c['position'] ?? 0);
    $byId[$c['id']]        = $c;
}

foreach ($links as $l) {
    if (isset($l['tags']) && is_string($l['tags'])) {
        $l['tags'] = json_decode($l['tags'], true) ?: [];
    }
    if (!is_array($l['tags'] ?? null)) $l['tags'] = [];

    if (isset($l['meta']) && is_string($l['meta']) && $l['meta'] !== '') {
        $l['meta'] = json_decode($l['meta'], true) ?: null;
    }
    if (!is_array($l['meta'] ?? null)) $l['meta'] = null;

    $l['rating']      = (int)($l['rating'] ?? 0);
    $l['click_count'] = (int)($l['click_count'] ?? 0);
    $l['position']    = (int)($l['position'] ?? 0);
    $l['status']      = (string)($l['status'] ?? '');
    $l['note']        = (string)($l['note'] ?? '');
    $l['wayback_url'] = (string)($l['wayback_url'] ?? '');

    $due = (string)($l['due_date'] ?? '');
    if ($due === '' || $due === '0000-00-00' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        $l['due_date'] = null;
    }
    $l['last_clicked_at'] = $l['last_clicked_at'] ?: null;

    if (isset($byId[$l['category_id']])) {
        $byId[$l['category_id']]['links'][] = $l;
    }
}

$tree = [];
foreach ($byId as $id => &$c) {
    if ($c['parent_id'] && isset($byId[$c['parent_id']])) {
        $byId[$c['parent_id']]['children'][] = &$c;
    } else {
        $tree[] = &$c;
    }
}
unset($c);

$dataForJs = [
    'user'        => ['id' => (int)$user['id'], 'username' => $user['username'], 'is_admin' => (bool)$user['is_admin']],
    'data'        => $tree,
    'settings'    => $settings ?: new stdClass(),
    'collections' => $collections,
    'csrf'        => csrf_token(),
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>GTR — Personal Repository v10</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<link rel="stylesheet" href="/assets/css/main.css">
<script>
window.GTR_DATA = <?= json_encode($dataForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.API_BASE = '/api';
window.CSRF_TOKEN = window.GTR_DATA.csrf;
</script>
</head>
<body data-theme="dark" class="has-sidebar">

<div class="edit-banner">✏️ РЕЖИМ РЕДАКТИРОВАНИЯ</div>

<div class="marquee-wrap">
  <div class="marquee">🖤 GTR — Personal Repository 🖤 &nbsp; • &nbsp; v10.3 · PHP+MySQL &nbsp; • &nbsp; Ctrl+K палитра &nbsp; • &nbsp; Ctrl+Shift+L ссылка &nbsp; • &nbsp; Ctrl+B дерево &nbsp; • &nbsp; Ctrl+E редактор &nbsp; • &nbsp; j/k навигация &nbsp; • &nbsp; ? справка &nbsp; • &nbsp; 📅 <a href="/calendar" style="color:#4a9eff;text-decoration:none;">Календарь</a> &nbsp; • &nbsp; <?php if ($user['is_admin']): ?>👥 <a href="/admin/users" style="color:#f59e0b;text-decoration:none;">Юзеры</a> &nbsp; • &nbsp; 📊 <a href="/admin/stats" style="color:#ffcb3d;text-decoration:none;">Статистика</a> &nbsp; • &nbsp; <?php endif; ?>🚪 <a href="/logout" style="color:#ff6666;text-decoration:none;">Выход (<?= h($user['username']) ?>)</a> &nbsp; • &nbsp;</div>
</div>

<div class="top-bar">
  <div class="top-bar-inner">
    <span class="logo-small">GTR</span>
    <div class="search-wrap">
      <input type="search" id="search" placeholder="🔍 название, url, #тег…" autocomplete="off">
      <button class="search-clear" id="searchClear" aria-label="Очистить">✕</button>
    </div>
    <span class="search-stats" id="searchStats"></span>
    <span class="link-counter" id="totalLinksCounter" title="Всего: 0">📎 <b>0</b></span>
    <div class="view-switcher" id="viewSwitcher">
      <button data-view="tree" class="active" title="Дерево (Ctrl+1)">🌲</button>
      <button data-view="collections" title="Коллекции (Ctrl+2)">📚</button>
      <button data-view="kanban" title="Kanban (Ctrl+3)">📋</button>
    </div>
    <button class="icon-btn" id="cpBtn" title="Command Palette (Ctrl+K)">⌘</button>
    <span id="gtr-save-status" class="save-status" data-state="saved" title="Сохранено">💾</span>
    <button class="icon-btn" id="qcBtn" title="Быстрое добавление (Ctrl+Shift+L)">⚡</button>
    <button class="icon-btn" id="massToggle" title="Массовое (Ctrl+M)">☑️</button>
    <button class="icon-btn" id="editToggle" title="Редактор (Ctrl+E)">✏️</button>
    <button class="icon-btn" id="settingsBtn" title="Настройки">⚙️</button>
    <button class="icon-btn calendar-btn" id="calendarBtn" title="Календарь">📅</button>
    <button class="icon-btn" id="themeToggle" title="Тема">🌙</button>
  </div>
</div>

<div class="tags-bar" id="tagsBar">
  <span class="label">🏷 Теги:</span>
  <button class="tags-nav" id="tagsNavLeft" type="button" disabled="">◀</button>
  <div class="tags-scroll" id="tagsScroll"><span id="tagsList"></span></div>
  <button class="tags-nav" id="tagsNavRight" type="button">▶</button>
  <button class="tag-clear" id="tagsClear" style="display: none;">✕ Сбросить</button>
</div>

<div class="logo">
  <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
    <defs><linearGradient id="lg" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#d60000"></stop><stop offset="100%" stop-color="#446633"></stop></linearGradient></defs>
    <circle cx="100" cy="100" r="88" fill="none" stroke="url(#lg)" stroke-width="4"></circle>
    <circle cx="100" cy="100" r="78" fill="none" stroke="#555" stroke-width="1"></circle>
    <text x="100" y="72" text-anchor="middle" font-size="22" fill="#d60000" font-weight="bold" font-family="sans-serif" letter-spacing="2">GTR</text>
    <text x="100" y="130" text-anchor="middle" font-size="56" fill="#d60000">🖤</text>
    <text x="100" y="165" text-anchor="middle" font-size="10" fill="#446633" font-family="sans-serif" letter-spacing="1">REPOSITORY v10</text>
  </svg>
</div>

<!-- ═══════════════════════════════════════════════════════
     ТУЛБАР — 4 меню + 3 кнопки
     ═══════════════════════════════════════════════════════ -->
<div class="toolbar">
  <button id="addCategoryBtn" style="display: none;">➕ Категория</button>
  <button id="expandAll">↕ Раскрыть</button>
  <button id="collapseAll">↕ Свернуть</button>

  <!-- 💾 Файл -->
  <div class="file-dropdown">
    <button type="button" class="file-dropdown-toggle" id="fileMenuBtn" title="Экспорт / Импорт">
      💾 Файл <span class="caret">▼</span>
    </button>
    <div class="file-dropdown-menu" id="fileMenu" role="menu">
      <button type="button" id="printBtn" role="menuitem"><span class="fm-icon">🖨</span> Печать</button>

      <div class="fm-sep"></div>
      <div class="fm-section-label">📤 Экспорт</div>
      <button type="button" id="exportBtn" role="menuitem"><span class="fm-icon">💾</span> JSON</button>
      <button type="button" id="exportMarkdownBtn" role="menuitem"><span class="fm-icon">📝</span> Markdown</button>
      <button type="button" id="exportBookmarksBtn" role="menuitem"><span class="fm-icon">📤</span> Закладки браузера</button>
      <button type="button" id="exportCatBtn" role="menuitem"><span class="fm-icon">📁</span> Экспорт папки</button>

      <div class="fm-sep"></div>
      <div class="fm-section-label">📥 Импорт</div>
      <button type="button" id="importBtn" role="menuitem"><span class="fm-icon">📂</span> JSON / HTML / Bookmarks</button>
	  <button type="button" id="urlTxtBtn" role="menuitem"><span class="fm-icon">📄</span> Из TXT (с проверкой)</button>
    </div>
  </div>

  <!-- 🗂 Папки -->
  <div class="file-dropdown">
    <button type="button" class="file-dropdown-toggle" id="foldersMenuBtn" title="Структура репозитория">
      🗂 Папки <span class="caret">▼</span>
    </button>
    <div class="file-dropdown-menu" id="foldersMenu" role="menu">
      <button type="button" id="reorderBtn" role="menuitem"><span class="fm-icon">🔀</span> Расположение</button>
      <button type="button" id="rulesBtn" role="menuitem"><span class="fm-icon">🎯</span> Правила</button>
      <button type="button" id="smartSortBtn" role="menuitem"><span class="fm-icon">🧠</span> Умный разбор</button>
      <button type="button" id="findDomainBtn" role="menuitem"><span class="fm-icon">🌐</span> Домен — собрать все ссылки</button>
    </div>
  </div>

  <!-- 🧹 Очистка -->
  <div class="file-dropdown">
    <button type="button" class="file-dropdown-toggle" id="cleanMenuBtn" title="Чистка и проверка">
      🧹 Очистка <span class="caret">▼</span>
    </button>
    <div class="file-dropdown-menu" id="cleanMenu" role="menu">
      <button type="button" id="cleanTrackingBtn" role="menuitem"><span class="fm-icon">🧹</span> URL — трекинг</button>
      <button type="button" id="autotagsBtn" role="menuitem"><span class="fm-icon">🏷</span> Авто-теги</button>
      <div class="fm-sep"></div>
      <button type="button" id="findDupsBtn" role="menuitem"><span class="fm-icon">🔎</span> Дубликаты</button>
      <button type="button" id="checkLinksBtn" role="menuitem"><span class="fm-icon">🔗</span> Проверить всё</button>
    </div>
  </div>

  <!-- 📦 Снапшоты -->
  <div class="file-dropdown">
    <button type="button" class="file-dropdown-toggle" id="archiveMenuBtn" title="Снапшоты">
      📦 Снапшоты <span class="caret">▼</span>
    </button>
    <div class="file-dropdown-menu" id="archiveMenu" role="menu">
      <button type="button" id="waybackAllBtn" role="menuitem"><span class="fm-icon">📦</span> Wayback — все ссылки</button>
    </div>
  </div>

  <button id="statsBtn" class="stats-btn" title="Топ доменов">📊 Статистика</button>
  <button id="collectionsBtn" title="Коллекции">📚 Коллекции</button>
  <button id="trashBtn" title="Корзина (Ctrl+Shift+T)">🗑 Корзина <span id="trashCount"></span></button>
  <input type="file" id="importFile" accept=".json,application/json,.html,text/html" style="display:none">
</div>

<div id="no-results" class="no-results" style="display:none;">Ничего не найдено</div>

<main id="main"></main>

<div class="footer">
  <p>🖤 GTR v10.3 · PHP <?= PHP_VERSION ?> · MySQL · 👤 <?= h($user['username']) ?></p>
  <p style="margin-top:8px;font-size:.8em;opacity:.6">
    <kbd>Ctrl+K</kbd> палитра · <kbd>Ctrl+Shift+L</kbd> ссылка · <kbd>/</kbd> поиск · <kbd>j/k</kbd> навигация · <kbd>?</kbd> справка · <kbd>Ctrl+E</kbd> редактор · <kbd>Ctrl+M</kbd> массовое · <kbd>Ctrl+Shift+T</kbd> корзина · <kbd>Ctrl+B</kbd> дерево
  </p>
</div>

<button class="sidebar-toggle" id="sidebarToggle" title="Дерево папок (Ctrl+B)">☰</button>
<div class="sb-backdrop" id="sbBackdrop"></div>
<aside class="sidebar" id="sidebar" aria-hidden="true" inert="">
  <div class="sidebar-head">
    <h3>🌲 Дерево папок</h3>
    <button type="button" id="sidebarClose">✕</button>
  </div>
  <div class="sidebar-search">
    <input type="search" id="sidebarSearch" placeholder="🔍 Фильтр папок..." autocomplete="off">
  </div>
  <div class="sidebar-tree" id="sidebarTree"></div>
</aside>

<button class="to-top" id="toTop" aria-label="Наверх">↑</button>
<div class="toast" id="toast"></div>

<div class="mass-bar" id="massBar">
  <span class="count" id="massCount">Выбрано: 0</span>
  <button id="massSelectAll" type="button">✓ Все</button>
  <button id="massClear" type="button">✕ Снять</button>
  <button id="massMove" type="button" disabled="">📁 Переместить</button>
  <button id="massTags" type="button" disabled="">🏷 Теги</button>
  <button id="massStatus" type="button" disabled="">🎯 Статус</button>
  <button id="massDelete" type="button" class="danger" disabled="">🗑 Удалить</button>
</div>

<div class="modal-overlay" id="modalOverlay">
  <div class="modal" id="modalContent"></div>
</div>

<div class="cp-overlay" id="cpOverlay">
  <div class="cp-box">
    <div class="cp-input-wrap">
      <span class="cp-icon">⌘</span>
      <input class="cp-input" id="cpInput" type="text" placeholder="Команда, ссылка, папка, тег…" autocomplete="off" spellcheck="false">
      <span class="cp-hint">Esc</span>
    </div>
    <div class="cp-list" id="cpList"></div>
  </div>
</div>

<script src="/assets/js/main.js" defer></script>
<script src="/assets/js/gtr-features.js" defer></script>
</body>
</html>