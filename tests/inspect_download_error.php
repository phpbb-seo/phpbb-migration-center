<?php
require_once 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter/tests/http_acceptance_test.php';
// Let's print the error message body from download/file.php?id=436
$res = curl_req('http://localhost/bb_e2e/download/file.php?id=436', null, $auth_cookie);
echo "HTTP code: " . $res['code'] . "\n";
echo "Body:\n" . substr($res['body'], 0, 500) . "\n";
