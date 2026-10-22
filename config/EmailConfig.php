<?php
require_once __DIR__ . '/../includes/deployment_settings_helper.php';
class EmailConfig {
    public static function configure(\PHPMailer\PHPMailer\PHPMailer $mailer, $db = null): void { configureDeploymentMailer($mailer, $db); }
    public static function getSmtpHost() { return deploymentMailConfiguration()['host']; }
    public static function getSmtpUsername() { return deploymentMailConfiguration()['username']; }
    public static function getSmtpPassword() { return deploymentMailConfiguration()['password']; }
    public static function getSmtpPort() { return deploymentMailConfiguration()['port']; }
    public static function getSmtpFrom() { return deploymentMailConfiguration()['from_address']; }
}
