<?php
require_once __DIR__.'/../auth_api/onesignal/send_notification.php';
require_once __DIR__.'/../auth_api/email/email_manager.php';
function publicationCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
publicationCheck(sendOneSignalNotification(['app_id'=>'test'], '') === false, 'Missing push key refuses delivery before network access');
publicationCheck(sendOneSignalNotification(['app_id'=>''], 'synthetic') === false, 'Missing push application refuses delivery before network access');
try {
    new EmailAccountManager('', '', '');
    throw new LogicException('Missing cPanel configuration was accepted');
} catch (RuntimeException $error) {
    publicationCheck($error->getMessage() === 'cPanel is not configured', 'Missing cPanel configuration refuses connection');
}
$source=file_get_contents(__DIR__.'/../auth_api/onesignal/create_email.php');
publicationCheck(!preg_match('/^createEmail\(/m', $source), 'Including mailbox helper cannot create an example account');
$source=file_get_contents(__DIR__.'/../classes/send_alert.php');
foreach (['useJSON','useXML','useHTTPGet','doSendMessage'] as $function) {
    publicationCheck((bool)preg_match('/function '.preg_quote($function,'/').'\([^\n]*\)\s*\{\s*\/\/[^\n]*\n\s*return false;/', $source), "$function refuses unsupported SMS delivery");
}
