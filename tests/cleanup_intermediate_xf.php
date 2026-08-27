<?php
$p = new PDO('mysql:host=localhost;dbname=xen', 'root', '');
$p->exec("DELETE FROM xf_attachment WHERE attachment_id NOT IN (3, 4)");
$p->exec("DELETE FROM xf_attachment_data WHERE data_id NOT IN (9, 10)");
$p->exec("DELETE FROM xf_thread WHERE thread_id NOT IN (SELECT DISTINCT thread_id FROM xf_post WHERE post_id <= 7820) AND thread_id != 540");
$p->exec("DELETE FROM xf_post WHERE post_id NOT IN (SELECT post_id FROM (SELECT post_id FROM xf_post WHERE post_id <= 7820 UNION SELECT 7824) t)");
$p->exec("UPDATE xf_attachment SET content_id = 7824 WHERE attachment_id IN (3, 4)");

echo "Cleaned up intermediate test attempts.\n";
echo "Post count: " . $p->query("SELECT COUNT(*) FROM xf_post")->fetchColumn() . "\n";
echo "Thread count: " . $p->query("SELECT COUNT(*) FROM xf_thread")->fetchColumn() . "\n";
echo "User count: " . $p->query("SELECT COUNT(*) FROM xf_user")->fetchColumn() . "\n";
echo "Attachment count: " . $p->query("SELECT COUNT(*) FROM xf_attachment")->fetchColumn() . "\n";
echo "Attachment data count: " . $p->query("SELECT COUNT(*) FROM xf_attachment_data")->fetchColumn() . "\n";
