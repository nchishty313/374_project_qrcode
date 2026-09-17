<?php
$token = $_GET['token'] ?? '';
$session = $_GET['session'] ?? '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Verify (Phone)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: #f3f4f6;
        padding: 20px;
        color: #111;
    }
    .card {
        background: white;
        padding: 20px 25px;
        border-radius: 12px;
        max-width: 650px;
        margin: 40px auto;
        box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        text-align: center;
    }
    h2 {
        margin-bottom: 10px;
        color: #059669;
    }
    #instructions {
        font-size: 14px;
        margin-bottom: 15px;
        color: #555;
    }
    button {
        padding: 12px 20px;
        border: 0;
        background: #059669;
        color: white;
        border-radius: 10px;
        cursor: pointer;
        font-size: 14px;
        margin-top: 12px;
        transition: 0.2s;
    }
    button:hover {
        background: #047a50;
    }
    #out {
        margin-top: 15px;
        text-align: left;
        font-family: monospace;
        font-size: 14px;
        background: #f9fafb;
        padding: 12px;
        border-radius: 8px;
        max-height: 350px;
        overflow-y: auto;
        white-space: pre-wrap;
    }
    .line {
        margin-bottom: 4px;
    }
  </style>
</head>
<body>
  <div class="card">
    <h2>Device Verification</h2>
    <p id="instructions">Collecting device info — allow permissions if prompted.</p>
    <div id="out">Preparing device information...</div>
    <button id="approve">Approve Login</button>
  </div>

<script>
const tokenHex = "<?php echo htmlspecialchars($token); ?>";
const sessionId = "<?php echo htmlspecialchars($session); ?>";
const apiBase = 'http://ipv4/374-project-qrcode/api.php';

const outDiv = document.getElementById('out');
let lastIntegrity = null;

// Browser, OS, Device info
const browserName = (() => {
    const ua = navigator.userAgent;
    if (ua.includes("Edg")) return "Edge";
    if (ua.includes("Chrome")) return "Chrome";
    if (ua.includes("Safari") && !ua.includes("Chrome")) return "Safari";
    if (ua.includes("Firefox")) return "Firefox";
    return "Unknown";
})();

const osVersion = (() => {
    const match = navigator.userAgent.match(/OS (\d+_\d+(_\d+)?)/);
    return match ? match[1].replace(/_/g, ".") : "Unknown";
})();

const deviceModel = (() => {
    const match = navigator.userAgent.match(/\(([^;]+);/);
    return match ? match[1].trim() : "Unknown";
})();

const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

// Collect device integrity
async function collectIntegrity() {
    const integrity = {};
    try {
        integrity.userAgent = navigator.userAgent;
        integrity.platform = navigator.platform;
        integrity.language = navigator.language;
        integrity.timezone_offset = new Date().getTimezoneOffset();
        integrity.client_time = new Date().toISOString();

        integrity.browser = browserName;
        integrity.osVersion = osVersion;
        integrity.deviceModel = deviceModel;
        integrity.timezone = timezone;

        integrity.screen = {
            width: screen.width,
            height: screen.height,
            availWidth: screen.availWidth,
            availHeight: screen.availHeight,
            colorDepth: screen.colorDepth,
            pixelRatio: window.devicePixelRatio || 1
        };

        integrity.features = {
            webgl: (() => {
                try { 
                    const c = document.createElement('canvas'); 
                    return !!(c.getContext && c.getContext('webgl')); 
                } catch(e){ return false; }
            })(),
            serviceWorker: 'serviceWorker' in navigator,
            notifications: 'Notification' in window,
            localStorage: !!window.localStorage,
            sessionStorage: !!window.sessionStorage
        };

        integrity.sensors = {
            accelerometer: 'Accelerometer' in window || 'ondevicemotion' in window,
            gyroscope: 'Gyroscope' in window || false
        };

        if (navigator.getBattery) {
            const b = await navigator.getBattery();
            integrity.battery = {
                charging: b.charging,
                level: b.level,
                chargingTime: b.chargingTime,
                dischargingTime: b.dischargingTime
            };
        }

        integrity.permissions = {};
        if (navigator.permissions && navigator.permissions.query) {
            try {
                const cam = await navigator.permissions.query({name:'camera'}).catch(()=>null);
                if (cam) integrity.permissions.camera = cam.state;
            } catch(e){}
            try {
                const geo = await navigator.permissions.query({name:'geolocation'}).catch(()=>null);
                if (geo) integrity.permissions.geolocation = geo.state;
            } catch(e){}
        }

        integrity.adblock = typeof window.canRunAds === 'undefined' && !document.querySelector('ins.adsbygoogle');

        integrity.incognito = false;
        try {
            const fs = window.RequestFileSystem || window.webkitRequestFileSystem;
            if (fs) {
                await new Promise((res) => 
                    fs(window.TEMPORARY, 100, 
                        () => res(true), 
                        () => { integrity.incognito = true; res(false); }
                    )
                );
            }
        } catch(e){ integrity.incognito = true; }

    } catch(e) {
        console.warn("Integrity collection error:", e);
    }
    return integrity;
}

// Show integrity nicely
function showIntegrity(integrity, serverResp = null) {
    let html = '';
    for (const key in integrity) {
        if (typeof integrity[key] === 'object') {
            html += `<div class="line"><strong>${key}:</strong> ${JSON.stringify(integrity[key], null, 2).replace(/\n/g,'<br>')}</div>`;
        } else {
            html += `<div class="line"><strong>${key}:</strong> ${integrity[key]}</div>`;
        }
    }
    if (serverResp && serverResp.summary) {
        html += `<div class="line"><strong>Server Summary:</strong><br>${serverResp.summary.replace(/\n/g,'<br>')}</div>`;
        html += `<div class="line"><strong>Risk Score:</strong> ${serverResp.risk}</div>`;
    }
    outDiv.innerHTML = html;
}

// Collect integrity immediately
collectIntegrity().then(i => {
    lastIntegrity = i;
    showIntegrity(lastIntegrity);
});

// Approve login
document.getElementById('approve').addEventListener('click', async () => {
    if (!lastIntegrity) {
        alert('Device integrity not collected yet. Please wait.');
        return;
    }

    const body = { token: tokenHex, integrity: lastIntegrity };
    outDiv.innerHTML = '<div class="line"><em>Sending approval to server...</em></div>';

    try {
        const resp = await fetch(apiBase + '?action=approve_login', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(body)
        });

        const text = await resp.text();
        let j;
        try { j = JSON.parse(text); } catch {
            outDiv.innerHTML = "<div class='line'>Server returned invalid JSON:<br>" + text + "</div>";
            return;
        }

        if (!j.ok) {
            outDiv.innerHTML = '<div class="line">Server error:<br>' + JSON.stringify(j, null, 2).replace(/\n/g,'<br>') + '</div>';
            return;
        }

        showIntegrity(lastIntegrity, j);

        if (j.status === 'approved') {
            alert('Login approved. Return to laptop.');
            document.getElementById('approve').style.display = 'none';
        } else if (j.status === 'denied') {
            alert('Login denied due to risk: ' + j.risk);
        }

    } catch(err) {
        outDiv.innerHTML = '<div class="line">Network error: ' + err + '</div>';
    }
});
</script>
</body>
</html>
