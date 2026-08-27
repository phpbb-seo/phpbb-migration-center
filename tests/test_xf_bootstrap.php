<?php
require_once 'C:/xampp/htdocs/xen/src/XF.php';
\XF::start('C:/xampp/htdocs/xen');
$app = \XF::setupApp('XF\App');
echo "XenForo bootstrapped successfully: version " . \XF::$version . "\n";
