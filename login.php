<?php
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>QR Login (Laptop)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { 
    font-family: Arial, sans-serif; 
    padding: 20px; 
    background:#f7f8fa; 
}

.card { 
    background:white; 
    border-radius:8px; 
    padding:20px; 
    max-width:520px; 
    margin:auto; 
    box-shadow:0 6px 18px rgba(0,0,0,.06); 
    text-align:center; 
}

#qr { 
    width:260px; 
    height:260px; 
    margin:auto; 
    margin-bottom:10px; 
}

#qr canvas { 
    display: block; 
    margin: 0 auto; 
    margin-bottom:65px; 
}

#qr p { 
    font-size:12px; 
    margin: 8px 0 16px 0; 
}

#status { 
    margin-top:20px; 
    font-weight:600; 
}

#details { 
    margin-top:8px; 
    font-size:14px; 
    color:#444; 
    word-wrap: break-word; 
}

button { 
    padding:10px 14px; 
    border-radius:6px; 
    border:0; 
    background:#2563eb; 
    color:white; 
    cursor:pointer; 
    margin-top:12px; 
}
  </style>
</head>
<body>
  <div class="card">
    <h2>QR Login Demo</h2>
    <div id="qr"></div>
    <div id="status">Generating QR…</div>
    <div id="details"></div>
    <div style="margin-top:14px;">
      <button id="regen">Regenerate</button>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>

  <script>
  const apiBase = '/374-project-qrcode/api.php'; 
  let sessionId = null;
  let tokenHex = null;
  const qrDiv = document.getElementById('qr');
  const statusDiv = document.getElementById('status');
  const detailsDiv = document.getElementById('details');
  const regenBtn = document.getElementById('regen');
  let pollInterval = null;

  async function generateSession() {
    statusDiv.textContent = "Contacting server...";
    try {
      const resp = await fetch(`${apiBase}?action=generate_session`, {method:'POST'});
      const data = await resp.json();
      if (!data.ok) throw new Error(JSON.stringify(data));

      sessionId = data.session_id;
      tokenHex = data.token_hex;

      const baseUrl = `http://IPv4`;
      const verifyUrl = `${baseUrl}/374-project-qrcode/verify.php?token=${tokenHex}&session=${sessionId}`;
      renderQR(verifyUrl);

      statusDiv.textContent = "Waiting for phone approval...";
      detailsDiv.textContent = `Session ID: ${sessionId}`;
      startPolling();
    } catch (err) {
      statusDiv.textContent = "Error generating session";
      detailsDiv.textContent = err.toString();
    }
  }

  function renderQR(url) {
    qrDiv.innerHTML = '';
    const qrEl = document.createElement('canvas');
    qrDiv.appendChild(qrEl);
    new QRious({ element: qrEl, value: url, size: 260, level: 'H' });
    const p = document.createElement('p');
    p.style.fontSize = '12px';
    p.style.margin = '8px 0 16px 0';
    p.textContent = "Scan with phone camera (same Wi-Fi)";
    qrDiv.appendChild(p);
  }

  function startPolling() {
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(async () => {
      if (!sessionId) return;
      try {
        const resp = await fetch(`${apiBase}?action=check_status&session_id=${encodeURIComponent(sessionId)}`);
        const j = await resp.json();
        if (!j.ok) { statusDiv.textContent = 'Error'; detailsDiv.textContent = JSON.stringify(j); return; }

        statusDiv.textContent = 'Status: ' + j.status;

        if (j.status === 'approved') {
            clearInterval(pollInterval);
            statusDiv.textContent = 'Approved! Redirecting...';

            // fetch integrity using correct pending login ID
            const integrityResp = await fetch(`${apiBase}?action=get_integrity&pending_id=${j.id}`);
            const integrityData = await integrityResp.json();
            const summary = integrityData.ok ? integrityData.data.summary : 'No summary available';

            // redirect to dashboard with session + summary
            const dashboardUrl = `/374-project-qrcode/dashboard.php?session=${encodeURIComponent(sessionId)}&summary=${encodeURIComponent(summary)}`;
            window.location.href = dashboardUrl;
        }

      } catch (err) {
        statusDiv.textContent = 'Polling error';
        detailsDiv.textContent = err.toString();
      }
    }, 2000);
  }

  regenBtn.addEventListener('click', () => {
    if (pollInterval) clearInterval(pollInterval);
    sessionId = null;
    tokenHex = null;
    generateSession();
  });

  generateSession();
  </script>
</body>
</html>
