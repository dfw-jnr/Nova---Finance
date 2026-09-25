<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Services\AuthService;

$auth = new AuthService();
if ($auth->user()) {
    header('Location: /');
    exit;
}
$csrf = Csrf::token();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#0B0D10" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <title>Sign in — NOVA</title>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/css/app.css?v=7" />
  <script>
    try {
      var t = localStorage.getItem('nova-theme') || 'system';
      var r = t === 'system' ? (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark') : t;
      document.documentElement.setAttribute('data-theme', r);
    } catch (e) {}
  </script>
</head>
<body>
  <main class="auth">
    <div class="auth-card">
      <div class="brand-lockup">
        <span class="mark"></span><h1>NOVA</h1>
        <p>Personal Finance</p>
      </div>
      <form id="login-form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>" />
        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" required autocomplete="username" />
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required autocomplete="current-password" minlength="8" />
        </div>
        <p class="field-error" id="login-error" hidden></p>
        <button type="submit" class="btn btn--primary">Sign in</button>
      </form>
      <p class="auth-links">No account? <a href="/register.php">Create one</a></p>
    </div>
  </main>
  <script src="/assets/js/api.js"></script>
  <script>
    NovaAPI.setCsrf(document.querySelector('[name=_csrf]').value);
    document.getElementById('login-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const err = document.getElementById('login-error');
      err.hidden = true;
      const fd = new FormData(e.target);
      try {
        await NovaAPI.login({ email: fd.get('email'), password: fd.get('password') });
        location.href = '/';
      } catch (ex) {
        err.textContent = ex.message || 'Sign in failed';
        err.hidden = false;
      }
    });
  </script>
</body>
</html>
