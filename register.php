<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stats.php';

auth_start_session();
stats_log_visit('/register');

if (auth_user()) {
    redirect('/');
}

$cfg = $GLOBALS['_gtr_cfg'];
$registrationEnabled = $cfg['app']['registration_enabled'] ?? true;

// Первый запуск → на /login (там логика создания owner)
if (auth_needs_setup()) {
    redirect('/login');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $registrationEnabled) {
    csrf_verify();
    $username  = trim((string)($_POST['username'] ?? ''));
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($password !== $password2) {
        $error = 'Пароли не совпадают';
    } else {
        $res = auth_register_user($username, $password);
        if ($res['ok']) {
            redirect('/');
        }
        $error = $res['error'] ?? 'Ошибка регистрации';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Регистрация — GTR</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#1a1a1a 0%,#2d2d2d 100%);min-height:100vh;display:grid;place-items:center;padding:20px;color:#f0f0f0}
.card{background:#2a2a2a;border:1px solid #444;border-radius:16px;padding:40px 32px;max-width:420px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.icon{font-size:3em;text-align:center;margin-bottom:8px}
h1{text-align:center;color:#d60000;font-size:1.5em;margin-bottom:6px;letter-spacing:1px}
.subtitle{text-align:center;color:#999;font-size:.88em;margin-bottom:24px}
label{display:block;color:#aaa;font-size:.82em;margin-bottom:6px;margin-top:14px;font-weight:500}
label:first-of-type{margin-top:0}
input{width:100%;padding:12px 14px;background:#1a1a1a;border:1px solid #444;border-radius:8px;color:#f0f0f0;font-size:15px;font-family:inherit}
input:focus{outline:none;border-color:#6cc244;box-shadow:0 0 0 3px rgba(108,194,68,.2)}
button{width:100%;margin-top:20px;padding:13px;background:#4a7040;border:1px solid #4a7040;border-radius:8px;color:#fff;font-size:15px;font-weight:600;font-family:inherit;cursor:pointer}
button:hover:not(:disabled){background:#6cc244}
button:disabled{opacity:.6;cursor:not-allowed}
.error{background:rgba(214,0,0,.15);border:1px solid rgba(214,0,0,.4);color:#ff6666;padding:10px 14px;border-radius:6px;font-size:.85em;margin-bottom:16px}
.info{background:rgba(74,158,255,.1);border:1px solid rgba(74,158,255,.3);color:#4a9eff;padding:12px 14px;border-radius:6px;font-size:.85em;margin-bottom:16px;text-align:center;line-height:1.5}
.hint{text-align:center;color:#777;font-size:.8em;margin-top:20px;line-height:1.6}
.hint a{color:#6cc244;text-decoration:none}
.hint a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="card">
    <div class="icon">🖤</div>
    <h1>GTR</h1>
    <div class="subtitle">Создание аккаунта</div>

    <?php if (!$registrationEnabled): ?>
        <div class="info">🔒 Регистрация закрыта администратором</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($registrationEnabled): ?>
    <form method="POST" autocomplete="off">
        <?= csrf_field() ?>

        <label for="username">Логин</label>
        <input type="text" id="username" name="username" placeholder="a-z, 0-9, _ (3–32)"
            value="<?= h($_POST['username'] ?? '') ?>" required autofocus minlength="3" maxlength="32" pattern="[a-zA-Z0-9_]{3,32}">

        <label for="password">Пароль</label>
        <input type="password" id="password" name="password" placeholder="Минимум 6 символов" required minlength="6">

        <label for="password2">Повторите пароль</label>
        <input type="password" id="password2" name="password2" placeholder="Ещё раз" required minlength="6">

        <button type="submit">💾 Создать аккаунт</button>
    </form>
    <?php endif; ?>

    <div class="hint">
        Уже есть аккаунт? <a href="/login">Войти</a>
    </div>
</div>
</body>
</html>