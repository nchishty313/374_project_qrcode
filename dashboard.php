<?php
session_start();


$session = $_GET['session'] ?? '';
$summary = $_GET['summary'] ?? '';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Dashboard</title>
<style>
body { font-family: Arial, sans-serif; padding: 20px; background:#f7f8fa; }
.card { background:white; border-radius:8px; padding:20px; max-width:600px; margin:auto; box-shadow:0 6px 18px rgba(0,0,0,.06); }
h2 { color:#2563eb; }
</style>
</head>
<body>
<div class="card">
  <h2>Welcome to Dashboard</h2>
  <p><strong>Session ID:</strong> <?php echo htmlspecialchars($session); ?></p>
  <p><strong>Device Integrity Summary:</strong></p>
  <pre><?php echo htmlspecialchars($summary); ?></pre>
</div>
</body>
</html>
