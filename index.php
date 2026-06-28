<?php
require_once __DIR__ . '/lib/Session.php';
require_once __DIR__ . '/lib/Cleanup.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

Cleanup::run();

$session_id = isset($_GET['s']) ? preg_replace('/[^a-f0-9]/', '', strtolower($_GET['s'])) : null;
$role  = 'pc';
$error = null;

if ($session_id) {
    $role    = 'mobile';
    $session = Session::load($session_id);
    if (!$session || Session::isExpired($session)) {
        $error      = 'Relace neexistuje nebo vypršela. Naskenujte nový QR kód.';
        $session_id = null;
    } else {
        Session::touch($session_id, 'mobile');
    }
} else {
    $session_id = bin2hex(random_bytes(16));
    Session::create($session_id);
}

$proto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base       = $proto . '://' . $_SERVER['HTTP_HOST'];
$script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$mobile_url = $base . $script_dir . '/?' . http_build_query(['s' => $session_id]);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Flash Exchange</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body
  data-role="<?= htmlspecialchars($role) ?>"
  data-session="<?= htmlspecialchars($session_id ?? '') ?>"
  data-base="<?= htmlspecialchars($script_dir) ?>"
>

<header>
  <span class="logo">Flash Exchange</span>
  <?php if ($role === 'pc'): ?>
  <a href="<?= htmlspecialchars($script_dir . '/') ?>" class="btn-secondary btn-sm">Nová relace</a>
  <?php endif; ?>
</header>

<main>
<?php if ($error): ?>

  <div class="section">
    <p class="error"><?= htmlspecialchars($error) ?></p>
  </div>

<?php elseif ($role === 'pc'): ?>

  <div class="section" id="pc-qr">
    <p class="label">Naskenujte QR kód mobilem:</p>
    <div id="qr-code"></div>
    <p class="link-hint">nebo otevřete odkaz:<br>
      <a id="mobile-link" href="<?= htmlspecialchars($mobile_url) ?>"><?= htmlspecialchars($mobile_url) ?></a>
    </p>
    <p id="pc-partner-status" class="status status--waiting">⏳ Čekám na připojení mobilu…</p>
  </div>

  <div class="section" id="pc-ready" hidden>
    <p class="status status--ok">● Mobil připojen</p>
    <p class="label">Z počítače na mobil</p>
    <button class="btn-action" data-pc-action="pc_to_mobile_text">Odeslat text</button>
    <button class="btn-action" data-pc-action="pc_to_mobile_file">Odeslat soubor</button>
  </div>

  <div class="section" id="pc-form-text" hidden>
    <p class="status status--ok">● Mobil čeká na text</p>
    <label class="label" for="pc-text">Text k odeslání:</label>
    <textarea id="pc-text" rows="7" placeholder="Sem napište text…"></textarea>
    <button id="pc-send-text" class="btn-primary">Odeslat na mobil</button>
  </div>

  <div class="section" id="pc-form-file" hidden>
    <p class="status status--ok">● Mobil čeká na soubor</p>
    <label class="label" for="pc-file">Soubor k odeslání:</label>
    <input type="file" id="pc-file">
    <div id="pc-upload-progress" hidden>
      <progress id="pc-progress-bar" value="0" max="100"></progress>
      <span id="pc-progress-text">0 %</span>
    </div>
    <button id="pc-send-file" class="btn-primary">Odeslat na mobil</button>
  </div>

  <div class="section" id="pc-waiting" hidden>
    <p class="status status--ok">● Mobil odesílá</p>
    <p class="hint">⏳ Čekám na data z mobilu…</p>
  </div>

  <div class="section" id="pc-received" hidden>
    <p class="label">Přijato z mobilu</p>
    <div id="pc-received-content" class="received-box"></div>
    <div class="row">
      <button id="pc-copy" class="btn-secondary" hidden>Kopírovat</button>
    </div>
    <button id="pc-continue" class="btn-primary">Nový přenos</button>
  </div>

  <div class="section" id="pc-sent" hidden>
    <p class="sent-msg">✓ Odesláno</p>
    <button id="pc-continue-after-send" class="btn-primary">Nový přenos</button>
  </div>

  <div class="section" id="pc-expired" hidden>
    <p class="error">Relace vypršela.</p>
    <a href="/" class="btn-primary">Nová relace</a>
  </div>

<?php else: ?>

  <div class="section" id="mob-select">
    <p class="label">Z mobilu na počítač</p>
    <button class="btn-action" data-action="mobile_to_pc_text">Odeslat text</button>
    <button class="btn-action" data-action="mobile_to_pc_file">Odeslat soubor</button>
  </div>

  <div class="section" id="mob-form-text" hidden>
    <button class="btn-back" id="mob-back-text">← Zpět</button>
    <label class="label" for="mob-text">Text k odeslání:</label>
    <textarea id="mob-text" rows="9" placeholder="Sem napište text…"></textarea>
    <button id="mob-send-text" class="btn-primary">Odeslat na PC</button>
  </div>

  <div class="section" id="mob-form-file" hidden>
    <button class="btn-back" id="mob-back-file">← Zpět</button>
    <label class="label" for="mob-file">Soubor k odeslání:</label>
    <input type="file" id="mob-file">
    <div id="mob-upload-progress" hidden>
      <progress id="mob-progress-bar" value="0" max="100"></progress>
      <span id="mob-progress-text">0 %</span>
    </div>
    <button id="mob-send-file" class="btn-primary">Odeslat na PC</button>
  </div>

  <div class="section" id="mob-waiting" hidden>
    <p class="hint">⏳ Čekám na data z PC…</p>
    <button class="btn-back" id="mob-back-waiting">← Zpět</button>
  </div>

  <div class="section" id="mob-received" hidden>
    <p class="label">Přijato z PC</p>
    <div id="mob-received-content" class="received-box"></div>
    <div class="row" id="mob-received-actions"></div>
    <button class="btn-primary" id="mob-new">Nový přenos</button>
  </div>

  <div class="section" id="mob-sent" hidden>
    <p class="sent-msg">✓ Odesláno</p>
    <button class="btn-primary" id="mob-new-after-send">Nový přenos</button>
  </div>

  <div class="section" id="mob-expired" hidden>
    <p class="error">Relace vypršela.</p>
    <p class="hint">Naskenujte nový QR kód na PC.</p>
  </div>

<?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs/qrcode.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
