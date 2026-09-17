# 374_project_qrcode
#Initial README file

Connection is run via XAMPP currently, you can look up installation if you need but it runs the php so that you can search for it locally in your browser. The project folder needs to be in ../xampp/htdocs in order to run and ensure the Apache module is running in the Control Panel of XAMPP to search it.

Requirements:
XAMPP (Apache + PHP + MySQL/MariaDB)
Modern browser on laptop and mobile (must be on the same network)

Step 1 — Find your laptop's IPv4 address
Open Command Prompt (Win + R → cmd → Enter).
Run:
ipconfig
Look for the IPv4 Address under your active network (Wi-Fi or Ethernet).
Example: 192.168....
*This IP will be used in URLs so the mobile can reach the laptop.*

Step 2 — Upload db.sql to phpMyAdmin

Open phpMyAdmin via XAMPP (http://localhost/phpmyadmin).
Create a new database named qr_auth_demo.
Click the Import tab.
Choose your db.sql file and click Go.
This will create all required tables and columns (pending_logins, device_integrity, etc.).

Step 3 — Configure config.php
Adjust credentials if you set a MySQL password.

Step 4 — Place project files in XAMPP
Copy/clone all files into:
C:\xampp\htdocs\374-project-qrcode\

Files:
login.php → laptop login page
verify.php → mobile verification page
dashboard.php → shows session + device summary
api.php → backend API
config.php → database + thresholds
db.sql → database schema (already uploaded in Step 2

Step 5 — Update JS to use IPv4
login.php: Ensure QR URL uses your IPv4 address:
const baseUrl = 'http://192.168.15'; // replace with your laptop IPv4
const verifyUrl = `${baseUrl}/374-project-qrcode/verify.php?token=${tokenHex}&session=${sessionId}`;


verify.php: Ensure API calls point to your laptop:
const apiBase = 'http://192.168.15/374-project-qrcode/api.php';

Step 6 — Allow firewall access(if needed)
Open Windows Firewall → Allow an app → Allow Apache HTTP Server on Private networks.
Restart Apache in XAMPP.

Step 7 — Open laptop login page
Browser URL:
http://your-ipv4-address/374-project-qrcode/login.php

The QR code will be generated with your IPv4-based URL.

Step 8 — Scan QR with mobile device
Ensure mobile is on the same Wi-Fi.
Open the camera / QR scanner app and scan the QR code.
verify.php opens and collects device integrity info.

Step 9 — Approve login on mobile

Tap Approve Login.
Server calculates risk score and updates session status.

Step 10 — Laptop auto-redirect

Laptop polling detects approved session.

Automatically redirects to dashboard.php

Device integrity summary (UA, risk, adblock, incognito)
