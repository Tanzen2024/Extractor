<?= $this->extend('layout/main') ?>

<?= $this->section('breadcrumb') ?>
<ol class="breadcrumb float-sm-right">
    <li class="breadcrumb-item"><a href="<?= site_url('dashboard') ?>">Accueil</a></li>
    <li class="breadcrumb-item active">Connexion Oracle</li>
</ol>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">État de la connexion Oracle</h3>
    </div>
    <div class="card-body">
        <table class="table table-borderless w-auto">
            <tr>
                <th>DSN (TNS)</th>
                <td><code><?= esc($dsn ?: '(non défini)') ?></code></td>
            </tr>
            <tr>
                <th>Utilisateur</th>
                <td><code><?= esc($username ?: '(non défini)') ?></code></td>
            </tr>
            <tr>
                <th>Mot de passe</th>
                <td><em class="text-muted">masqué — jamais affiché</em></td>
            </tr>
            <tr>
                <th>Configuration</th>
                <td>
                    <?php if ($isConfigured): ?>
                        <span class="badge badge-success">Complète</span>
                    <?php else: ?>
                        <span class="badge badge-danger">Incomplète — vérifier le fichier .env</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <button id="btnTestOracle" class="btn btn-primary" <?= $isConfigured ? '' : 'disabled' ?>>
            <i class="fas fa-plug mr-1"></i> Tester la connexion
        </button>

        <div id="oracleTestResult" class="mt-3"></div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.getElementById('btnTestOracle')?.addEventListener('click', function () {
    const btn = this;
    const resultBox = document.getElementById('oracleTestResult');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Test en cours...';
    resultBox.innerHTML = '';

    bscdFetch('<?= site_url('admin/oracle/test') ?>', { method: 'POST' })
        .then((data) => {
            const cls = data.success ? 'alert-success' : 'alert-danger';
            resultBox.innerHTML = `<div class="alert ${cls} mb-0">${data.message}</div>`;
        })
        .catch(() => {
            resultBox.innerHTML = '<div class="alert alert-danger mb-0">Erreur inattendue lors de l\'appel au serveur.</div>';
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plug mr-1"></i> Tester la connexion';
        });
});
</script>
<?= $this->endSection() ?>
