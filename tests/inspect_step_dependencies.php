<?php
require_once 'C:/xampp/htdocs/bb_e2e/vendor/autoload.php';

spl_autoload_register(function($class) {
    $prefix = 'phpbbseo\\migrationcenter\\';
    if (strpos($class, $prefix) === 0) {
        $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = 'C:/xampp/htdocs/bb/ext/phpbbseo/migrationcenter/' . $rel . '.php';
        if (file_exists($file)) require_once $file;
    }
});

$step_classes = [
    'groups' => \phpbbseo\migrationcenter\source\xenforo\step\groups_step::class,
    'users' => \phpbbseo\migrationcenter\source\xenforo\step\users_step::class,
    'group_memberships' => \phpbbseo\migrationcenter\source\xenforo\step\group_memberships_step::class,
    'global_permissions' => \phpbbseo\migrationcenter\source\xenforo\step\global_permissions_step::class,
    'forums' => \phpbbseo\migrationcenter\source\xenforo\step\forums_step::class,
    'node_permissions' => \phpbbseo\migrationcenter\source\xenforo\step\node_permissions_step::class,
    'topics' => \phpbbseo\migrationcenter\source\xenforo\step\topics_step::class,
    'posts' => \phpbbseo\migrationcenter\source\xenforo\step\posts_step::class,
    'attachments' => \phpbbseo\migrationcenter\source\xenforo\step\attachments_step::class,
    'avatars' => \phpbbseo\migrationcenter\source\xenforo\step\avatars_step::class,
    'conversations' => \phpbbseo\migrationcenter\source\xenforo\step\conversations_step::class,
    'conversation_messages' => \phpbbseo\migrationcenter\source\xenforo\step\conversation_messages_step::class,
    'conversation_attachments' => \phpbbseo\migrationcenter\source\xenforo\step\conversation_attachments_step::class,
    'polls' => \phpbbseo\migrationcenter\source\xenforo\step\polls_step::class,
    'bans' => \phpbbseo\migrationcenter\source\xenforo\step\bans_step::class,
];

echo "=== Step Dependencies Inventory ===\n";
foreach ($step_classes as $name => $class) {
    $inst = new $class();
    echo str_pad($name, 25) . " => [" . implode(', ', $inst->get_dependencies()) . "]\n";
}
