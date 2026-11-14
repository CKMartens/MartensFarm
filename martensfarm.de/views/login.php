<?php ob_start(); ?>
<h1>Anmelden</h1>
<p class="muted">Gib deine E-Mail-Adresse ein. Du bekommst einen Anmelde-Link und einen Code – ganz ohne Passwort.</p>
<form method="post" action="<?= e(url('login')) ?>" class="stack">
    <?= csrf_field() ?>
    <label>E-Mail
        <input type="email" name="email" required autocomplete="email" autofocus maxlength="190">
    </label>
    <button type="submit" class="btn btn-primary btn-block">Anmelde-Link schicken</button>
</form>
<p class="muted small">Noch kein Zugang? Frag deinen Hof-Admin.</p>
<?php $body = ob_get_clean(); require __DIR__ . '/auth_layout.php';
