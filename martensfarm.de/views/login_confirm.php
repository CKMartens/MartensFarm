<?php ob_start(); ?>
<h1>Anmeldung bestätigen</h1>
<p class="muted">Ein Klick noch, dann bist du drin.</p>
<form method="post" action="<?= e(url('login/link')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <button type="submit" class="btn btn-primary btn-block">Jetzt anmelden</button>
</form>
<?php $body = ob_get_clean(); require __DIR__ . '/auth_layout.php';
