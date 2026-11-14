<?php ob_start(); ?>
<h1>Schau in dein Postfach</h1>
<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>
<p class="muted">Wenn die Adresse bei uns hinterlegt ist, ist jetzt eine Mail mit Anmelde-Link unterwegs. Klick auf den Link – oder gib hier den 6-stelligen Code aus der Mail ein:</p>
<form method="post" action="<?= e(url('login/code')) ?>" class="stack">
    <?= csrf_field() ?>
    <label>Code
        <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required class="code-input">
    </label>
    <button type="submit" class="btn btn-primary btn-block">Anmelden</button>
</form>
<p class="muted small">Keine Mail bekommen? Schau im Spam-Ordner nach oder <a href="<?= e(url('login')) ?>">fordere einen neuen Link an</a>.</p>
<?php $body = ob_get_clean(); require __DIR__ . '/auth_layout.php';
