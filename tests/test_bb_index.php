<?php
$ch = curl_init('http://localhost/bb/index.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP Response: {$code} (Length: " . strlen($res) . " bytes)\n";
