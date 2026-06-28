(function () {
  'use strict';

  const role      = document.body.dataset.role;
  const sessionId = document.body.dataset.session;
  const base      = document.body.dataset.base;

  if (!sessionId) return;

  let pollTimer      = null;
  let pcState        = 'qr';     // pc only
  let mobState       = 'select'; // mobile only
  let lastAction     = null;     // tracks last known action; null→null means nothing happened

  // ── Helpers ────────────────────────────────────────────────────────

  function show(id) {
    document.querySelectorAll('main .section').forEach(el => { el.hidden = true; });
    const el = document.getElementById(id);
    if (el) el.hidden = false;
  }

  function formatSize(bytes) {
    if (bytes < 1024)    return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  function apiUrl(path) { return base + '/' + path; }

  function postJson(path, params) {
    return fetch(apiUrl(path), {
      method: 'POST',
      body: new URLSearchParams(params),
    }).then(r => r.json());
  }

  // ── QR code (PC only) ──────────────────────────────────────────────

  if (role === 'pc' && window.QRCode) {
    const link = document.getElementById('mobile-link');
    if (link) {
      new QRCode(document.getElementById('qr-code'), {
        text: link.href, width: 200, height: 200,
        correctLevel: QRCode.CorrectLevel.M,
      });
    }
  }

  // ── Polling (always running; stops only on expiry) ─────────────────

  function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(poll, 1000);
  }

  function stopPolling() {
    clearInterval(pollTimer);
    pollTimer = null;
  }

  async function poll() {
    let data;
    try {
      const resp = await fetch(
        apiUrl('api/poll.php') + '?s=' + sessionId + '&role=' + role,
        { cache: 'no-store' }
      );
      data = await resp.json();
    } catch { return; }

    if (!data.ok) {
      if (data.error === 'expired') {
        stopPolling();
        show(role === 'pc' ? 'pc-expired' : 'mob-expired');
      }
      return;
    }

    if (role === 'pc') handlePcPoll(data);
    else               handleMobilePoll(data);
  }

  // ── PC poll handler ────────────────────────────────────────────────

  function handlePcPoll(data) {
    // Auto-reset: other side called reset.php → action went from non-null to null
    if (lastAction !== null && data.action === null) {
      lastAction = null;
      if (pcState === 'waiting' || pcState === 'sent' || pcState === 'received') {
        pcReady();
        return;
      }
    }
    if (data.action !== null) lastAction = data.action;

    // Update QR screen status
    if (pcState === 'qr') {
      const st = document.getElementById('pc-partner-status');
      if (st) {
        st.textContent = data.partner_connected ? '● Mobil připojen' : '⏳ Čekám na připojení mobilu…';
        st.className   = data.partner_connected ? 'status status--ok' : 'status status--waiting';
      }
      if (data.partner_connected) {
        pcState = 'connected';
        show('pc-ready');
      } else {
        return;
      }
    }

    // Process action only from idle state (not mid-transfer)
    if (data.action && pcState === 'connected') {
      if (data.action.startsWith('pc_to_')) {
        // PC triggered this itself via button click; show form
        pcState = data.action.endsWith('text') ? 'sender-text' : 'sender-file';
        show(pcState === 'sender-text' ? 'pc-form-text' : 'pc-form-file');
      } else {
        // Mobile is sending to PC
        pcState = 'waiting';
        show('pc-waiting');
      }
    }

    // Receive incoming data
    if (data.pending && pcState === 'waiting') {
      pcState = 'received';
      showPcReceived(data.pending);
    }
  }

  // Reset PC to idle (local only — no server call)
  function pcReady() {
    pcState    = 'connected';
    lastAction = null;
    show('pc-ready');
  }

  // Reset PC + notify server (triggered by button click)
  function pcReset() {
    pcReady();
    postJson('api/reset.php', { session_id: sessionId, role: 'pc' }).catch(() => {});
  }

  function showPcReceived(pending) {
    show('pc-received');
    const box     = document.getElementById('pc-received-content');
    const copyBtn = document.getElementById('pc-copy');
    box.innerHTML = '';
    if (copyBtn) copyBtn.hidden = true;

    if (pending.type === 'text') {
      box.textContent = pending.content;
      if (copyBtn) {
        copyBtn.hidden = false;
        copyBtn.onclick = () => {
          navigator.clipboard.writeText(pending.content).then(() => {
            copyBtn.textContent = '✓ Zkopírováno';
            setTimeout(() => { copyBtn.textContent = 'Kopírovat'; }, 2000);
          });
        };
      }
    } else {
      const a = document.createElement('a');
      a.href      = apiUrl('api/file.php') + '?s=' + sessionId + '&transfer_id=' + pending.transfer_id;
      a.download  = pending.filename;
      a.className = 'btn-primary';
      a.textContent = '⬇ Stáhnout ' + pending.filename + ' (' + formatSize(pending.filesize) + ')';
      box.appendChild(a);
    }
  }

  // ── Mobile poll handler ────────────────────────────────────────────

  function handleMobilePoll(data) {
    // Auto-reset: other side called reset.php → action went from non-null to null
    if (lastAction !== null && data.action === null) {
      lastAction = null;
      if (mobState === 'waiting' || mobState === 'sent' || mobState === 'received') {
        mobileContinue();
        return;
      }
    }
    if (data.action !== null) lastAction = data.action;

    // PC selected a "pc_to_mobile_*" action → mobile should automatically wait
    if (data.action && data.action.startsWith('pc_to_') && mobState === 'select') {
      mobState = 'waiting';
      show('mob-waiting');
    }

    // Receive incoming data
    if (data.pending && mobState === 'waiting') {
      mobState = 'received';
      showMobReceived(data.pending);
    }
  }

  // Reset mobile to idle (local only)
  function mobileContinue() {
    mobState   = 'select';
    lastAction = null;
    show('mob-select');
  }

  // Reset mobile + notify server (triggered by button click or back)
  async function goBack() {
    mobileContinue();
    try { await postJson('api/reset.php', { session_id: sessionId, role: 'mobile' }); } catch {}
  }

  function showMobReceived(pending) {
    show('mob-received');
    const box     = document.getElementById('mob-received-content');
    const actions = document.getElementById('mob-received-actions');
    box.innerHTML     = '';
    actions.innerHTML = '';

    if (pending.type === 'text') {
      box.textContent = pending.content;
      const btn = document.createElement('button');
      btn.className   = 'btn-secondary';
      btn.textContent = 'Kopírovat';
      btn.onclick = () => {
        navigator.clipboard.writeText(pending.content).then(() => {
          btn.textContent = '✓ Zkopírováno';
          setTimeout(() => { btn.textContent = 'Kopírovat'; }, 2000);
        });
      };
      actions.appendChild(btn);
    } else {
      const a = document.createElement('a');
      a.href      = apiUrl('api/file.php') + '?s=' + sessionId + '&transfer_id=' + pending.transfer_id;
      a.download  = pending.filename;
      a.className = 'btn-primary';
      a.textContent = '⬇ Stáhnout ' + pending.filename + ' (' + formatSize(pending.filesize) + ')';
      actions.appendChild(a);
    }
  }

  // ── PC action buttons ──────────────────────────────────────────────

  document.querySelectorAll('[data-pc-action]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const action = btn.dataset.pcAction;
      try {
        const r = await postJson('api/action.php', { session_id: sessionId, action, role: 'pc' });
        if (!r.ok) { alert('Chyba při volbě akce.'); return; }
      } catch { alert('Chyba připojení.'); return; }
      lastAction = action;
      pcState    = action.endsWith('text') ? 'sender-text' : 'sender-file';
      show(pcState === 'sender-text' ? 'pc-form-text' : 'pc-form-file');
    });
  });

  // ── Mobile action buttons ──────────────────────────────────────────

  document.querySelectorAll('[data-action]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const action = btn.dataset.action;
      try {
        const r = await postJson('api/action.php', { session_id: sessionId, action, role: 'mobile' });
        if (!r.ok) { alert('Chyba při volbě akce.'); return; }
      } catch { alert('Chyba připojení.'); return; }
      lastAction = action;
      mobState   = action.endsWith('text') ? 'sender-text' : 'sender-file';
      show(mobState === 'sender-text' ? 'mob-form-text' : 'mob-form-file');
    });
  });

  // ── Back buttons ───────────────────────────────────────────────────

  ['mob-back-text', 'mob-back-file', 'mob-back-waiting'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', goBack);
  });

  // ── Send text: PC ──────────────────────────────────────────────────

  const pcSendText = document.getElementById('pc-send-text');
  if (pcSendText) {
    pcSendText.addEventListener('click', async () => {
      const text = document.getElementById('pc-text').value.trim();
      if (!text) { alert('Zadejte text.'); return; }
      pcSendText.disabled = true;
      try {
        const r = await postJson('api/send.php', { session_id: sessionId, role: 'pc', type: 'text', text });
        if (r.ok) { pcState = 'sent'; show('pc-sent'); }
        else alert('Chyba při odesílání.');
      } catch { alert('Chyba připojení.'); }
      pcSendText.disabled = false;
    });
  }

  // ── Send file: PC ──────────────────────────────────────────────────

  const pcSendFile = document.getElementById('pc-send-file');
  if (pcSendFile) {
    pcSendFile.addEventListener('click', () => {
      const fi = document.getElementById('pc-file');
      if (!fi.files.length) { alert('Vyberte soubor.'); return; }
      pcSendFile.disabled = true;
      uploadFile('pc', fi.files[0],
        () => { pcState = 'sent'; show('pc-sent'); },
        'pc-upload-progress', 'pc-progress-bar', 'pc-progress-text',
        () => { pcSendFile.disabled = false; }
      );
    });
  }

  // ── Send text: Mobile ──────────────────────────────────────────────

  const mobSendText = document.getElementById('mob-send-text');
  if (mobSendText) {
    mobSendText.addEventListener('click', async () => {
      const text = document.getElementById('mob-text').value.trim();
      if (!text) { alert('Zadejte text.'); return; }
      mobSendText.disabled = true;
      try {
        const r = await postJson('api/send.php', { session_id: sessionId, role: 'mobile', type: 'text', text });
        if (r.ok) { mobState = 'sent'; show('mob-sent'); }
        else alert('Chyba při odesílání.');
      } catch { alert('Chyba připojení.'); }
      mobSendText.disabled = false;
    });
  }

  // ── Send file: Mobile ──────────────────────────────────────────────

  const mobSendFile = document.getElementById('mob-send-file');
  if (mobSendFile) {
    mobSendFile.addEventListener('click', () => {
      const fi = document.getElementById('mob-file');
      if (!fi.files.length) { alert('Vyberte soubor.'); return; }
      mobSendFile.disabled = true;
      uploadFile('mobile', fi.files[0],
        () => { mobState = 'sent'; show('mob-sent'); },
        'mob-upload-progress', 'mob-progress-bar', 'mob-progress-text',
        () => { mobSendFile.disabled = false; }
      );
    });
  }

  // ── File upload via XHR ────────────────────────────────────────────

  function uploadFile(fileRole, file, onSuccess, wrapId, barId, textId, onError) {
    const fd = new FormData();
    fd.append('session_id', sessionId);
    fd.append('role', fileRole);
    fd.append('type', 'file');
    fd.append('file', file);

    const wrap = document.getElementById(wrapId);
    const bar  = document.getElementById(barId);
    const txt  = document.getElementById(textId);
    if (wrap) wrap.hidden = false;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', apiUrl('api/send.php'));

    if (bar) {
      xhr.upload.onprogress = e => {
        if (!e.lengthComputable) return;
        const pct = Math.round(e.loaded / e.total * 100);
        bar.value = pct;
        if (txt) txt.textContent = pct + ' %';
      };
    }

    xhr.onload = () => {
      if (wrap) wrap.hidden = true;
      try {
        const r = JSON.parse(xhr.responseText);
        if (r.ok) onSuccess();
        else { alert('Chyba při nahrávání.'); if (onError) onError(); }
      } catch { alert('Chyba serveru.'); if (onError) onError(); }
    };

    xhr.onerror = () => {
      if (wrap) wrap.hidden = true;
      alert('Chyba připojení.');
      if (onError) onError();
    };

    xhr.send(fd);
  }

  // ── "Nový přenos" buttons ─────────────────────────────────────────

  ['pc-continue', 'pc-continue-after-send'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', pcReset);
  });

  ['mob-new', 'mob-new-after-send'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', goBack);
  });

  // ── Init ───────────────────────────────────────────────────────────

  startPolling();
})();
