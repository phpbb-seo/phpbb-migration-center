<?php
$pdo = new PDO('mysql:host=localhost;dbname=bb;charset=utf8mb4', 'root', '');
$admin = $pdo->query("SELECT user_id, username, user_email FROM phpbb_users WHERE user_type = 3")->fetch(PDO::FETCH_ASSOC);
echo "Admin User on bb:\n";
print_r($admin);
