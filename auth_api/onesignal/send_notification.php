<?php
require_once __DIR__ . '/../../config/EnvConfig.php';
// onesignal/send_notification.php
// Helper functions for sending OneSignal notifications

function sendNotificationToDevice($deviceId, $title, $message) {
    $appId = EnvConfig::get('ONESIGNAL_APP_ID', '');
    $apiKey = EnvConfig::get('ONESIGNAL_API_KEY', '');

    $content = ["en" => $message];
    $headings = ["en" => $title];

    $fields = [
        "app_id" => $appId,
        "include_player_ids" => [$deviceId],
        "headings" => $headings,
        "contents" => $content,
        "priority" => 10
    ];

    return sendOneSignalNotification($fields, $apiKey);
}

function sendOneSignalNotification($fields, $apiKey) {
    if ($apiKey === '' || empty($fields['app_id'])) return false;
    try {
        $fields = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json; charset=utf-8",
            "Authorization: Basic " . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('Curl error: ' . curl_error($ch));
            return false;
        }

        curl_close($ch);

        return $httpCode == 200;
    } catch (Exception $e) {
        error_log("Error sending notification: " . $e->getMessage());
        return false;
    }
}