<?php
$p = new PDO('mysql:host=localhost;dbname=xen', 'root', '');
echo 'thread_watch: ' . $p->query('SELECT COUNT(*) FROM xf_thread_watch')->fetchColumn() . "\n";
echo 'forum_watch: ' . $p->query('SELECT COUNT(*) FROM xf_forum_watch')->fetchColumn() . "\n";
