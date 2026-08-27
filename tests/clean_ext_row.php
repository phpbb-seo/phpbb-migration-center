<?php
$pdo = new PDO('mysql:host=localhost;dbname=bb;charset=utf8mb4', 'root', '');
$pdo->exec("DELETE FROM phpbb_ext WHERE ext_name = 'phpbbseo/migrationcenter'");
echo "Deleted ext record from phpbb_ext.\n";
