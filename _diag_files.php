<?php
declare(strict_types=1);
// /_diag_files.php — инвентаризация файлов GTR v10.1

$root = __DIR__;

// ══════════════════════════════════════════════════════
// Классификация
// ══════════════════════════════════════════════════════

$critical = [
    '.env' => 'конфиг БД',
    '.htaccess' => 'правила Apache',
    'favicon.svg' => 'иконка сайта',
    'index.php' => 'главная',
    'login.php' => 'вход',
    'logout.php' => 'выход',
    'register.php' => 'регистрация 🆕',
    'calendar.php' => 'календарь',
    'stat.php' => 'ping',
    'includes/config.php' => 'конфиг',
    'includes/db.php' => 'PDO',
    'includes/csrf.php' => 'CSRF',
    'includes/helpers.php' => 'утилиты',
    'includes/auth.php' => 'авторизация',
    'includes/stats.php' => 'статистика',
    'api/_bootstrap.php' => 'API bootstrap',
    'api/categories.php' => 'API категории',
    'api/links.php' => 'API ссылки',
    'api/settings.php' => 'API настройки',
    'api/collections.php' => 'API коллекции',
    'api/export.php' => 'API экспорт',
    'api/notes.php' => 'API заметки',
    'api/trash.php' => 'API корзина',
    'api/snapshots.php' => 'API снапшоты',
    'api/autotags.php' => 'API авто-теги',
    'api/rules.php' => 'API правила',
    'api/import.php' => 'API импорт',
    'admin/stats.php' => 'админ-статистика',
    'admin/users.php' => 'админ-юзеры 🆕',
    'assets/css/main.css' => 'CSS',
    'assets/js/main.js' => 'JS основной',
    'assets/js/gtr-features.js' => 'JS расширения',
];

$diagnostics = [
    '_diag.php' => 'старая диагностика v1',
    '_diag_utf8.php' => 'диагностика кодировки',
    '_diag_date.php' => 'диагностика due_date',
    '_diag_fix.php' => 'фикс БД',
    '_diag_wipe.php' => 'очистка БД',
    '_diag_files.php' => 'этот файл',
    'check.php' => 'старый check v1',
    'tools/migrate.php' => 'миграция',
    'tools/migrate-v10.sql' => 'SQL миграции',
    'tools/check-utf8.php' => 'utf8-check',
    'tools/fix-db.php' => 'fix БД',
    'tools/wipe.php' => 'wipe',
    'tools/import-file.php' => 'CLI импорт',
    'tools/info.php' => 'phpinfo-мини',
];

$archives = [
    'GTR v9.14 full-php.html' => 'старая v9.14',
    'gtr-v9.14-2026-10-17.json' => 'дамп импорта',
];

// Паттерны мусора
$junk_patterns = [
    '/\.bak$/i' => '.bak',
    '/\.old$/i' => '.old',
    '/\.orig$/i' => '.orig',
    '/\.tmp$/i' => '.tmp',
    '/\.swp$/i' => '.swp',
    '/~$/' => '~ (editor)',
    '/\.log$/i' => '.log',
    '/^Thumbs\.db$/i' => 'Thumbs.db',
    '/^\.DS_Store$/' => '.DS_Store',
];

// ══════════════════════════════════════════════════════
// Сканирование
// ══════════════════════════════════════════════════════

$files = [];
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iter as $f) {
    if ($f->isDir()) continue;
    $rel = ltrim(str_replace($root, '', $f->getPathname()), '/\\');
    $rel = str_replace('\\', '/', $rel);
    $files[$rel] = [
        'full' => $f->getPathname(),
        'size' => $f->getSize(),
        'mtime' => $f->getMTime(),
    ];
}
ksort($files);

$buckets = ['critical' => [], 'diag' => [], 'archive' => [], 'junk' => [], 'unknown' => []];
$totalSize = 0;

foreach ($files as $path => $meta) {
    $totalSize += $meta['size'];
    $info = $meta + ['path' => $path, 'bucket' => 'unknown', 'reason' => ''];

    if (isset($critical[$path])) {
        $info['bucket'] = 'critical';
        $info['reason'] = $critical[$path];
    } elseif (isset($diagnostics[$path])) {
        $info['bucket'] = 'diag';
        $info['reason'] = $diagnostics[$path];
    } elseif (isset($archives[$path])) {
        $info['bucket'] = 'archive';
        $info['reason'] = $archives[$path];
    } else {
        foreach ($junk_patterns as $pat => $label) {
            if (preg_match($pat, basename($path))) {
                $info['bucket'] = 'junk';
                $info['reason'] = 'паттерн ' . $label;
                break;
            }
        }
        if ($info['bucket'] === 'unknown' && preg_match('/\.json$/i', $path)) {
            $info['bucket'] = 'archive';
            $info['reason'] = 'JSON-дамп';
        }
        if ($info['bucket'] === 'unknown' && preg_match('/\.html?$/i', $path) && basename($path) !== 'index.php') {
            $info['bucket'] = 'archive';
            $info['reason'] = 'старый HTML';
        }
        if ($info['bucket'] === 'unknown' && basename($path) === '_diag_files.php') {
            $info['bucket'] = 'diag';
            $info['reason'] = 'этот файл';
        }
    }
    $buckets[$info['bucket']][] = $info;
}

$missing = [];
foreach ($critical as $path => $desc) {
    if (!isset($files[$path])) $missing[$path] = $desc;
}

$sumSize = function(array $arr): int {
    $s = 0; foreach ($arr as $i) $s += $i['size']; return $s;
};
$fmt = function(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    return round($b / 1048576, 2) . ' MB';
};

function renderTable(array $items, string $badgeClass, string $badgeText): void {
    if (!$items) return;
    $fmt = function(int $b): string {
        if ($b < 1024) return $b . ' B';
        if ($b < 1048576) return round($b / 1024, 1) . ' KB';
        return round($b / 1048576, 2) . ' MB';
    };
    $now = time();
    echo '<table><thead><tr><th></th><th>Файл</th><th>Размер</th><th>Возраст</th><th>Назначение</th></tr></thead><tbody>';
    foreach ($items as $i) {
        $d = $now - $i['mtime'];
        if ($d < 3600) $age = round($d/60) . ' мин';
        elseif ($d < 86400) $age = round($d/3600) . ' ч';
        elseif ($d < 2592000) $age = round($d/86400) . ' дн';
        else $age = round($d/2592000) . ' мес';
        echo '<tr>';
        echo '<td><span class="badge ' . $badgeClass . '">' . $badgeText . '</span></td>';
        echo '<td class="path">' . htmlspecialchars($i['path']) . '</td>';
        echo '<td class="size">' . $fmt($i['size']) . '</td>';
        echo '<td class="age">' . $age . '</td>';
        echo '<td class="reason">' . htmlspecialchars($i['reason']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
?>
<!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8">
<title>GTR — инвентаризация файлов</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#1a1a1a;color:#e0e0e0;padding:24px;line-height:1.5;font-size:14px}
h1{color:#6cc244;font-size:1.6em;margin-bottom:8px}
h2{color:#4a9eff;font-size:1.15em;margin:24px 0 10px;padding-bottom:6px;border-bottom:1px solid #333;display:flex;align-items:center;gap:8px}
h2 .count{background:color-mix(in srgb,#4a9eff 30%,transparent);color:#4a9eff;padding:2px 10px;border-radius:10px;font-size:.8em;font-weight:600}
h2 .size{color:#888;font-size:.85em;font-weight:400;margin-left:auto}
.box{background:#2a2a2a;border:1px solid #444;border-radius:10px;padding:16px;margin-bottom:16px}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin:16px 0}
.stat{background:#2a2a2a;border:1px solid #444;border-radius:8px;padding:12px;text-align:center}
.stat .num{font-size:1.8em;font-weight:800;line-height:1}
.stat .lbl{font-size:.75em;color:#888;margin-top:4px;text-transform:uppercase;letter-spacing:.5px}
.stat.ok .num{color:#6cc244}
.stat.warn .num{color:#ffb700}
.stat.danger .num{color:#ff3030}
.stat.info .num{color:#4a9eff}
table{width:100%;border-collapse:collapse;font-size:.85em}
th,td{padding:6px 10px;text-align:left;border-bottom:1px solid #333}
th{color:#888;font-weight:600;text-transform:uppercase;font-size:.78em;letter-spacing:.5px}
td.path{font-family:'JetBrains Mono','Courier New',monospace;color:#90c870;word-break:break-all}
td.size{text-align:right;color:#888;white-space:nowrap;width:90px}
td.age{color:#666;white-space:nowrap;width:100px;font-size:.85em}
td.reason{color:#aaa;font-size:.85em}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.7em;font-weight:700;letter-spacing:.3px}
.b-ok{background:#15301a;color:#6cc244;border:1px solid #2a5a2e}
.b-diag{background:#302a15;color:#ffb700;border:1px solid #5a4a1e}
.b-archive{background:#151530;color:#4a9eff;border:1px solid #2a2a5a}
.b-junk{background:#301515;color:#ff3030;border:1px solid #5a2a2a}
.b-unknown{background:#2a2a2a;color:#888;border:1px solid #444}
.warn{margin-top:16px;padding:12px 16px;background:#3a1515;border:1px solid #ff3030;border-radius:8px;color:#ffb0b0}
.info{margin-top:16px;padding:12px 16px;background:#15301a;border:1px solid #6cc244;border-radius:8px;color:#b0e8b0}
.muted{color:#666;font-size:.85em}
code{background:#1a1a1a;padding:1px 6px;border-radius:4px;color:#90c870;font-size:.9em}
</style>
</head><body>

<h1>📁 GTR — инвентаризация файлов v10.1</h1>
<p class="muted">Корень: <code><?= htmlspecialchars($root) ?></code></p>
<p class="muted">Просканировано: <b><?= count($files) ?></b> файлов · Всего: <b><?= $fmt($totalSize) ?></b></p>

<div class="summary">
    <div class="stat ok"><div class="num"><?= count($buckets['critical']) ?></div><div class="lbl">✅ Нужные</div></div>
    <div class="stat warn"><div class="num"><?= count($buckets['diag']) ?></div><div class="lbl">🧹 Диагностика</div></div>
    <div class="stat info"><div class="num"><?= count($buckets['archive']) ?></div><div class="lbl">🗑 Архивы</div></div>
    <div class="stat danger"><div class="num"><?= count($buckets['junk']) ?></div><div class="lbl">🚨 Мусор</div></div>
    <div class="stat"><div class="num"><?= count($buckets['unknown']) ?></div><div class="lbl">❓ Неизвестно</div></div>
</div>

<?php if ($missing): ?>
<div class="warn">
    <b>⚠️ Отсутствуют <?= count($missing) ?> ОСНОВНЫХ файлов:</b><br>
    <?php foreach ($missing as $path => $desc): ?>
        • <code><?= htmlspecialchars($path) ?></code> — <?= htmlspecialchars($desc) ?><br>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($buckets['junk']): ?>
<h2>🚨 Мусор <span class="count"><?= count($buckets['junk']) ?></span> <span class="size"><?= $fmt($sumSize($buckets['junk'])) ?></span></h2>
<div class="box">
    <p style="margin-bottom:10px;color:#ffb0b0;">✅ <b>Смело удаляй</b> — .bak / .old / .log / .tmp</p>
    <?php renderTable($buckets['junk'], 'b-junk', '🗑 УДАЛИТЬ'); ?>
</div>
<?php endif; ?>

<?php if ($buckets['archive']): ?>
<h2>🗑 Архивы и дампы <span class="count"><?= count($buckets['archive']) ?></span> <span class="size"><?= $fmt($sumSize($buckets['archive'])) ?></span></h2>
<div class="box">
    <p style="margin-bottom:10px;color:#b0c8ff;">✅ <b>Удаляй после успешной перезаливки</b></p>
    <?php renderTable($buckets['archive'], 'b-archive', '🗑 АРХИВ'); ?>
</div>
<?php endif; ?>

<?php if ($buckets['diag']): ?>
<h2>🧹 Диагностика <span class="count"><?= count($buckets['diag']) ?></span> <span class="size"><?= $fmt($sumSize($buckets['diag'])) ?></span></h2>
<div class="box">
    <p style="margin-bottom:10px;color:#ffe0a0;">⚠️ <b>Только после завершения работ</b></p>
    <?php renderTable($buckets['diag'], 'b-diag', '🧹 УДАЛИТЬ'); ?>
</div>
<?php endif; ?>

<?php if ($buckets['unknown']): ?>
<h2>❓ Неизвестные <span class="count"><?= count($buckets['unknown']) ?></span> <span class="size"><?= $fmt($sumSize($buckets['unknown'])) ?></span></h2>
<div class="box">
    <p style="margin-bottom:10px;color:#ccc;">🤔 <b>Проверь сам</b></p>
    <?php renderTable($buckets['unknown'], 'b-unknown', '❓ ПРОВЕРЬ'); ?>
</div>
<?php endif; ?>

<h2>✅ Основные — не трогать <span class="count"><?= count($buckets['critical']) ?> / <?= count($critical) ?></span> <span class="size"><?= $fmt($sumSize($buckets['critical'])) ?></span></h2>
<div class="box">
    <p style="margin-bottom:10px;color:#b0e8b0;">🔒 <b>Это работает GTR</b></p>
    <?php renderTable($buckets['critical'], 'b-ok', '✅ РАБОЧИЙ'); ?>
</div>

<div class="info">
    <b>💡 Порядок очистки:</b><br>
    1. <b>🚨 Мусор</b> — удаляй сразу<br>
    2. <b>🗑 Архивы</b> — после проверки импорта<br>
    3. <b>🧹 Диагностика</b> — в самом конце<br>
    4. <b>❓ Неизвестные</b> — реши сам<br>
    5. <b>✅ Основные</b> — не трогай<br><br>
    ⚠️ <b>После проверки удали сам <code>_diag_files.php</code>!</b>
</div>

<p class="muted" style="margin-top:24px;text-align:center;">
    Сгенерировано: <?= date('Y-m-d H:i:s') ?>
</p>

</body></html>