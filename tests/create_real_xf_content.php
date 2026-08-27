<?php
require_once 'C:/xampp/htdocs/xen/src/XF.php';
\XF::start('C:/xampp/htdocs/xen');
$app = \XF::setupApp('XF\App');
$em = $app->em();

// 1. User Creation
$existingUser = $em->findOne('XF:User', ['username' => 'Migrated_Test_User']);
if (!$existingUser) {
    /** @var \XF\Entity\User $user */
    $user = $em->create('XF:User');
    $user->username = 'Migrated_Test_User';
    $user->email = 'persian_test_user@example.local';
    $user->user_group_id = 2; // Registered
    $user->user_state = 'valid';
    $user->is_staff = 0;
    
    /** @var \XF\Entity\UserAuth $auth */
    $auth = $user->getRelationOrDefault('Auth');
    $auth->setPassword('PersianPass!12345');
    
    /** @var \XF\Entity\UserProfile $profile */
    $profile = $user->getRelationOrDefault('Profile');
    $profile->location = 'London, UK';
    $profile->about = 'Developer and forum admin in London';
    $profile->signature = 'Test signature with beautiful typography and emoji 🚀';
    
    /** @var \XF\Entity\UserOption $option */
    $option = $user->getRelationOrDefault('Option');
    $option->receive_admin_email = 1;
    
    $user->save();
    echo "Created user Migrated_Test_User (ID: {$user->user_id})\n";
} else {
    $user = $existingUser;
    echo "User Migrated_Test_User exists (ID: {$user->user_id})\n";
}

// 2. Avatar Creation
$tempAvatarPath = sys_get_temp_dir() . '/temp_avatar.png';
$im = imagecreatetruecolor(192, 192);
$bg = imagecolorallocate($im, 41, 128, 185); // Blue
$textColor = imagecolorallocate($im, 255, 255, 255);
imagefill($im, 0, 0, $bg);
imagestring($im, 5, 40, 85, "PERSIAN AVATAR", $textColor);
imagepng($im, $tempAvatarPath);
imagedestroy($im);

/** @var \XF\Service\User\AvatarService $avatarService */
$avatarService = $app->service('XF:User\AvatarService', $user);
$avatarService->setImage($tempAvatarPath);
$avatarService->updateAvatar();
@unlink($tempAvatarPath);
echo "Avatar updated! avatar_date: {$user->avatar_date}, avatar_width: {$user->avatar_width}, avatar_height: {$user->avatar_height}\n";

// 3. Forum Creation
$existingForum = $em->findOne('XF:Node', ['title' => 'Test Multibyte Discussion Forum']);
if (!$existingForum) {
    /** @var \XF\Entity\Node $node */
    $node = $em->create('XF:Node');
    $node->title = 'Test Multibyte Discussion Forum';
    $node->node_name = 'persian-arabic-forum';
    $node->description = 'Special forum for testing character encoding and Unicode emojis';
    $node->node_type_id = 'Forum';
    $node->parent_node_id = 0;
    $node->display_order = 1;
    $node->save();

    /** @var \XF\Entity\Forum $forum */
    $forum = $em->create('XF:Forum');
    $forum->node_id = $node->node_id;
    $forum->allow_posting = 1;
    $forum->save();
    echo "Created Forum (Node ID: {$node->node_id})\n";
} else {
    $forum = $existingForum->Data;
    echo "Forum exists (Node ID: {$existingForum->node_id})\n";
}

// 4. Attachments Creation
$tempImgPath = sys_get_temp_dir() . '/temp_attach.png';
$im = imagecreatetruecolor(400, 250);
$bg = imagecolorallocate($im, 46, 204, 113); // Green
$white = imagecolorallocate($im, 255, 255, 255);
imagefill($im, 0, 0, $bg);
imagestring($im, 5, 60, 110, "INLINE ATTACHMENT IMAGE", $white);
imagepng($im, $tempImgPath);
imagedestroy($im);

$tempPdfPath = sys_get_temp_dir() . '/temp_attach.pdf';
$pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 595 842]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF";
file_put_contents($tempPdfPath, $pdfContent);

$attachHandler = $app->repository('XF:Attachment')->getAttachmentHandler('post');

$fileWrapperImg = new \XF\FileWrapper($tempImgPath, 'test_image_sample.png');
/** @var \XF\Service\Attachment\PreparerService $preparer */
$preparer = $app->service('XF:Attachment\PreparerService');
$dataImg = $preparer->insertDataFromFile($fileWrapperImg, $user->user_id);

$fileWrapperPdf = new \XF\FileWrapper($tempPdfPath, 'test_document_sample.pdf');
$dataPdf = $preparer->insertDataFromFile($fileWrapperPdf, $user->user_id);

echo "Attachment Data created: Image data_id: {$dataImg->data_id}, PDF data_id: {$dataPdf->data_id}\n";

// 5. Thread & Post Creation
$zwnjWord = "UnicodeRunner\xE2\x80\x8CXXX"; // U+200C
$postMessage = "XYX YK XXX XXXXYXY Multibyte_Sample XXX.\n\n"
    . "XX XX Unicode {$zwnjWord} X XXXX LibraryCatalog Multibyte_Sample X XXXX LibraryCatalogArabic XXXY XYXX XX XXXXX UnicodeRunner‌KXYX.\n\n"
    . "XXXX Y X Y_Ar X K X K_Ar XX XXXXX XYXXXY 🚀 ✨ 🌟.\n\n"
    . "[B]XXX XXYX XXXXYXY[/B] X [I]XXX KX[/I] X [URL=https://example.com]XYXXX XXXXX[/URL].\n\n"
    . "[ATTACH=full]{$dataImg->data_id}[/ATTACH]\n\n"
    . "XYXXX XXX XXXY XXX KX XXYX XX XXXX XXXXXXY XX XXXXXY XXX XXXYX XXXX XXX.";

\XF::asVisitor($user, function() use ($app, $em, $forum, $postMessage, $user, $preparer, $attachHandler, $dataImg, $dataPdf, $tempImgPath, $tempPdfPath) {
    /** @var \XF\Service\Thread\CreatorService $threadCreator */
    $threadCreator = $app->service('XF:Thread\CreatorService', $forum);
    $threadCreator->setContent('Test Topic Multibyte English XX XYXXXY 🚀 X Unicode «UnicodeRunner»', $postMessage);
    $thread = $threadCreator->save();
    $post = $thread->FirstPost;

    /** @var \XF\Entity\Attachment $attachImg */
    $attachImg = $em->create('XF:Attachment');
    $attachImg->data_id = $dataImg->data_id;
    $attachImg->content_type = 'post';
    $attachImg->content_id = $post->post_id;
    $attachImg->attach_date = time();
    $attachImg->unassociated = 0;
    $attachImg->save();

    /** @var \XF\Entity\Attachment $attachPdf */
    $attachPdf = $em->create('XF:Attachment');
    $attachPdf->data_id = $dataPdf->data_id;
    $attachPdf->content_type = 'post';
    $attachPdf->content_id = $post->post_id;
    $attachPdf->attach_date = time();
    $attachPdf->unassociated = 0;
    $attachPdf->save();

    // Update post message with the real attach_id
    $finalMessage = str_replace("[ATTACH=full]{$dataImg->data_id}[/ATTACH]", "[ATTACH=full]{$attachImg->attachment_id}[/ATTACH]", $postMessage);
    $post->message = $finalMessage;
    $post->attach_count = 2;
    $post->save();

    @unlink($tempImgPath);
    @unlink($tempPdfPath);

    echo "=== REAL XENFORO CONTENT SUMMARY ===\n";
    echo "User ID: {$user->user_id}, Username: {$user->username}\n";
    echo "Avatar file: data/avatars/o/0/{$user->user_id}.jpg (exists: " . (file_exists("C:/xampp/htdocs/xen/data/avatars/o/0/{$user->user_id}.jpg") ? "YES" : "NO") . ")\n";
    echo "Forum ID: {$forum->node_id}, Title: {$forum->Node->title}\n";
    echo "Thread ID: {$thread->thread_id}, Title: {$thread->title}\n";
    echo "Post ID: {$post->post_id}\n";
    echo "Attach 1: ID {$attachImg->attachment_id}, name: {$attachImg->Data->filename}, file_key: {$attachImg->Data->file_key}\n";
    echo "Attach 2: ID {$attachPdf->attachment_id}, name: {$attachPdf->Data->filename}, file_key: {$attachPdf->Data->file_key}\n";
    echo "SUCCESS!\n";
});
