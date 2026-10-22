<?php
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/EnvConfig.php';
require_once('Connections/coop.php');

//use DateTime;
use onesignal\client\api\DefaultApi;
use onesignal\client\Configuration;
use onesignal\client\model\GetNotificationRequestBody;
use onesignal\client\model\Notification;
use onesignal\client\model\StringMap;
use onesignal\client\model\Player;
use onesignal\client\model\UpdatePlayerTagsRequestBody;
use onesignal\client\model\ExportPlayersRequestBody;
use onesignal\client\model\Segment;
use onesignal\client\model\FilterExpressions;
use PHPUnit\Framework\TestCase;
//use GuzzleHttp;

define('APP_ID', EnvConfig::get('ONESIGNAL_APP_ID', ''));
define('APP_KEY_TOKEN', EnvConfig::get('ONESIGNAL_API_KEY', ''));


$config = Configuration::getDefaultConfiguration()
    ->setAppKeyToken(APP_KEY_TOKEN);
//  ->setUserKeyToken(USER_KEY_TOKEN);

$apiInstance = new DefaultApi(
    new GuzzleHttp\Client(),
    $config
);


function createNotificationGeneral($enContent): Notification
{
    if (APP_ID === '' || APP_KEY_TOKEN === '') throw new RuntimeException('Push notifications are not configured');
    global $conn;

    $sql = "INSERT INTO tbl_notification (subject,message,coop_id,date) VALUES ('Notification',?, -1, now())";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$enContent]);
    } catch (PDOException $e) {
        // error_log($e->getMessage()); 
    }


    $content = new StringMap();
    $content->setEn($enContent);

    $notification = new Notification();
    $notification->setAppId(APP_ID);

    $notification->setContents($content);
    $notification->setIncludedSegments(['Subscribed Users']);
    //$notification->setIncludePlayerIds(['e30bb2e6-2429-4eed-99c7-cc2ca1c7a4ad']);

    return $notification;
}

function createNotificationPlayer($enContent, $player, $coopid): Notification
{
    if (APP_ID === '' || APP_KEY_TOKEN === '') throw new RuntimeException('Push notifications are not configured');

    global $conn;

    $sql = "INSERT INTO tbl_notification (subject,message,coop_id,date) VALUES ('Notification', ?, ?, now())";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$enContent, $coopid]);
    } catch (PDOException $e) {
        // error_log($e->getMessage());
    }


    $content = new StringMap();
    $content->setEn($enContent);

    $notification = new Notification();
    $notification->setAppId(APP_ID);

    $notification->setContents($content);

    //$notification->setIncludePlayerIds(['e30bb2e6-2429-4eed-99c7-cc2ca1c7a4ad']);
    $notification->setIncludePlayerIds([$player]);
    return $notification;
}