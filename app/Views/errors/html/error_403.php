<!DOCTYPE html>
<html lang="<?= esc(function_exists('current_locale') ? current_locale() : 'fr', 'attr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex">
    <title><?= esc(lang('Auth.forbidden.title')) ?></title>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background:#f4f6f9; color:#3d4852; display:flex; min-height:100vh; margin:0; align-items:center; justify-content:center; }
        .box { text-align:center; padding:2rem; }
        h1 { font-size:4rem; margin:0; color:#dc3545; }
        p { margin:.5rem 0; }
        a { color:#007bff; }
        code { background:#e9ecef; padding:.1rem .35rem; border-radius:3px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>403</h1>
        <p><strong><?= esc(lang('Auth.forbidden.heading')) ?></strong></p>
        <p><?= esc(lang('Auth.forbidden.text')) ?></p>
        <?php if (! empty($permission)): ?>
            <p class="text-muted"><?= esc(lang('Auth.forbidden.permission')) ?> <code><?= esc($permission) ?></code></p>
        <?php endif; ?>
        <p><a href="<?= site_url('dashboard') ?>"><?= esc(lang('Auth.forbidden.back')) ?></a></p>
    </div>
</body>
</html>
