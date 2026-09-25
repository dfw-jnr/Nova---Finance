<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/app/bootstrap.php';
use Nova\Services\AuthService;
use Nova\Helpers\Csrf;

$auth = new AuthService();
if (!$auth->user()) {
    header('Location: /login.php');
    exit;
}
$csrf = Csrf::token();
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content" />
  <meta name="color-scheme" content="dark light" />
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#F3F4F6" />
  <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0B0D10" />
  <meta name="theme-color" content="#0B0D10" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="apple-mobile-web-app-title" content="NOVA" />
  <meta name="description" content="NOVA — Personal Finance" />
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>" />
  <link rel="manifest" href="/manifest.json" />
  <link rel="apple-touch-icon" href="/assets/icons/icon-192.png" />
  <title>NOVA — Personal Finance</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet" href="/assets/css/app.css?v=15" />
  <link rel="stylesheet" href="/assets/css/components.css?v=15" />
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" onload="this.onload=null;this.rel='stylesheet'" />
  <noscript><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" /></noscript>  <script>
    (function () {
      try {
        var t = localStorage.getItem('nova-theme') || 'system';
        var r = t === 'system'
          ? (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
          : t;
        document.documentElement.setAttribute('data-theme', r);
      } catch (e) {}
    })();
  </script>
</head>
<body class="app-body">
<?php
$html = file_get_contents(__DIR__ . '/app-shell.html');
if ($html === false) {
    echo '<p>Missing app-shell.html</p>';
} else {
    echo $html;
}
?>
</body>
</html>
