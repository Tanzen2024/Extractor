<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex">
    <title>403 — Accès refusé</title>
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
        <p><strong>Accès refusé.</strong></p>
        <p>Vous êtes authentifié mais votre rôle ne vous accorde pas cette action.</p>
        <?php if (! empty($permission)): ?>
            <p class="text-muted">Permission requise : <code><?= esc($permission) ?></code></p>
        <?php endif; ?>
        <p><a href="<?= site_url('dashboard') ?>">Retour au tableau de bord</a></p>
    </div>
</body>
</html>
