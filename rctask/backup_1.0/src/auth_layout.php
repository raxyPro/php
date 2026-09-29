<?php /** Shared <head> for login and register pages. Expects $title. */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= h(env_title_prefix()) ?><?= h($title ?? 'rcfamily') ?> · rcfamily</title>
  <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
  <meta name="theme-color" content="<?= env_theme_color() ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="rctask">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="assets/app.css?v=2">
</head>
<body class="env-<?= app_env() ?>">
<?= env_banner() ?>
<div class="center">
