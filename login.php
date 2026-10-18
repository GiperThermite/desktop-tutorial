<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stats.php';

auth_start_session();
stats_log_visit('/login.php');

// ═══════════════════════════════════════════════════════
// Безопасный расчёт $next — защита от циклов
// ═══════════════════════════════════════════════════════
$next = (string)($_GET['next'] ?? '');
if (
    $next === '' ||
    strlen($next) > 200 ||                 // защита от накопления
    $next[0] !== '/' ||                    // должен начинаться с /
    str_starts_with($next, '//') ||        // не protocol-relative
    str_contains($next, 'login.php') ||    // НЕ на сам login
    str_contains($next, 'logout.php')      // НЕ на logout
) {
    $next = '/';
}

// Уже залогинен → редирект ТОЛЬКО на безопасный next
if (auth_user()) {
    redirect($next);
}

$needsSetup = auth_needs_setup();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username  = trim((string)($_POST['username'] ?? ''));
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($needsSetup) {
        if ($password !== $password2) {
            $error = 'Пароли не совпадают';
        } else {
            $res = auth_register_first($username, $password);
            if ($res['ok']) {
                redirect($next);
            } else {
                $error = $res['error'];
            }
        }
    } else {
        $res = auth_login($username, $password);
        if ($res['ok']) {
            redirect($next);
        } else {
            $error = $res['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Вход — GTR Repository</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#1a1a1a 0%,#2d2d2d 100%);min-height:100vh;display:grid;place-items:center;padding:20px;color:#f0f0f0}
.card{background:#2a2a2a;border:1px solid #444;border-radius:16px;padding:40px 32px;max-width:400px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.icon{font-size:3em;text-align:center;margin-bottom:8px;filter:drop-shadow(0 0 20px rgba(214,0,0,.5))}
h1{text-align:center;color:#d60000;font-size:1.5em;margin-bottom:6px;letter-spacing:1px;text-shadow:0 0 10px rgba(214,0,0,.4)}
.subtitle{text-align:center;color:#999;font-size:.88em;margin-bottom:24px}
label{display:block;color:#aaa;font-size:.82em;margin-bottom:6px;margin-top:14px;font-weight:500}
label:first-of-type{margin-top:0}
input{width:100%;padding:12px 14px;background:#1a1a1a;border:1px solid #444;border-radius:8px;color:#f0f0f0;font-size:15px;font-family:inherit;transition:border-color .2s}
input:focus{outline:none;border-color:#6cc244;box-shadow:0 0 0 3px rgba(108,194,68,.2)}
button{width:100%;margin-top:20px;padding:13px;background:#4a7040;border:1px solid #4a7040;border-radius:8px;color:#fff;font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .2s}
button:hover:not(:disabled){background:#6cc244;border-color:#6cc244;transform:translateY(-1px)}
button:disabled{opacity:.6;cursor:not-allowed}
.error{background:rgba(214,0,0,.15);border:1px solid rgba(214,0,0,.4);color:#ff6666;padding:10px 14px;border-radius:6px;font-size:.85em;margin-bottom:16px}
.hint{text-align:center;color:#777;font-size:.75em;margin-top:20px;line-height:1.6}
.setup-banner{background:rgba(108,194,68,.1);border:1px solid rgba(108,194,68,.3);color:#6cc244;padding:12px 14px;border-radius:6px;font-size:.85em;margin-bottom:16px;text-align:center}
</style>
</head>
<body>
<div class="card">
    <div class="icon">🖤</div>
    <h1>GTR Repository</h1>
    <div class="subtitle">
        <?= $needsSetup ? 'Первый запуск' : 'Вход в систему' ?>
    </div>

    <?php if ($needsSetup): ?>
        <div class="setup-banner">⚡ Создайте пароль владельца. Восстановление невозможно.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="<?= $needsSetup ? 'off' : 'on' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= h($next) ?>">

        <label for="username"><?= $needsSetup ? 'Логин владельца' : 'Логин' ?></label>
        <input
            type="text"
            id="username"
            name="username"
            placeholder="<?= $needsSetup ? 'admin (a-z, 0-9, _)' : 'Ваш логин' ?>"
            value="<?= h($_POST['username'] ?? '') ?>"
            required
            autofocus
            minlength="3"
            maxlength="64"
            pattern="[a-zA-Z0-9_]{3,64}">

        <label for="password">Пароль</label>
        <input
            type="password"
            id="password"
            name="password"
            placeholder="<?= $needsSetup ? 'Минимум 6 символов' : 'Ваш пароль' ?>"
            required
            autocomplete="<?= $needsSetup ? 'new-password' : 'current-password' ?>">

        <?php if ($needsSetup): ?>
            <label for="password2">Повторите пароль</label>
            <input
                type="password"
                id="password2"
                name="password2"
                placeholder="Ещё раз"
                required
                autocomplete="new-password">
        <?php endif; ?>

        <button type="submit"><?= $needsSetup ? '💾 Создать и войти' : '🔓 Войти' ?></button>
    </form>

    <div class="hint">
    <?php if ($needsSetup): ?>
        После создания пароля вы сможете<br>добавлять категории и ссылки.
    <?php else: ?>
        Нет аккаунта? <a href="/register" style="color:#6cc244;">Создать</a><br>
        <span style="font-size:.9em;opacity:.7;">Пароль защищает данные.</span>
    <?php endif; ?>
</div>
</div>
</body>
</html>