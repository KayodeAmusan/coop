<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../auth_api/utils/EmailService.php';
require_once __DIR__.'/../config/EnvConfig.php';
require_once __DIR__.'/../config/EmailConfig.php';
require_once __DIR__.'/../libs/services/EmailQueueManager.php';

// Exercise the real mail configuration and message builder without network access.
class MailRuntimeDatabase {
    public $enabled = true;
    public function prepare($sql) { return new MailRuntimeStatement($this); }
}
class MailRuntimeStatement {
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function execute($params) { assertSameValue(['smtp'], $params); }
    public function fetch($mode) {
        return ['enabled'=>$this->db->enabled, 'config_json'=>json_encode([
            'host'=>'smtp.example.invalid','port'=>465,'username'=>'synthetic-user',
            'encryption'=>'tls','from_email'=>'sender@example.invalid','from_name'=>'Synthetic cooperative'
        ]), 'encrypted_secrets'=>'{}'];
    }
}
class CapturedSecurityMail extends PHPMailer\PHPMailer\PHPMailer {
    public function send() { return $this->preSend(); }
}
$db = new MailRuntimeDatabase();
test('actual security email service uses deployment settings without connecting in constructor', function() use ($db) {
    $service = new EmailService($db);
    $property = new ReflectionProperty(EmailService::class, 'mailer');
    $mail = $property->getValue($service);
    assertSameValue('smtp.example.invalid', $mail->Host);
    assertSameValue(465, $mail->Port);
    assertSameValue('ssl', $mail->SMTPSecure);
    assertSameValue('sender@example.invalid', $mail->From);
    assertSameValue(0, $mail->SMTPDebug);
});
test('OTP is addressed only to its intended recipient and recipients are cleared on reuse', function() use ($db) {
    $service = new EmailService($db);
    $mail = new CapturedSecurityMail(true);
    configureDeploymentMailer($mail, $db);
    $mail->addCC('previous@example.invalid');
    $mail->addBCC('previous-hidden@example.invalid');
    (new ReflectionProperty(EmailService::class, 'mailer'))->setValue($service, $mail);
    assertSameValue(true, $service->sendOTP('member@example.invalid', '123456'));
    assertSameValue([['member@example.invalid','']], $mail->getToAddresses());
    assertSameValue([], $mail->getCcAddresses());
    assertSameValue([], $mail->getBccAddresses());
    assertContains('123456', $mail->Body);
    assertSameValue(true, $service->sendOTP('second@example.invalid', '654321'));
    assertSameValue([['second@example.invalid','']], $mail->getToAddresses());
});
test('legacy configuration API resolves through the deployment helper', function() use ($db) {
    assertSameValue('smtp.example.invalid', EnvConfig::getMailConfig($db)['host']);
    $mail = new CapturedSecurityMail(true);
    EmailConfig::configure($mail, $db);
    assertSameValue('Synthetic cooperative', $mail->FromName);
});
test('explicitly disabled SMTP cannot configure a sending client', function() use ($db) {
    $db->enabled = false;
    try { new EmailService($db); throw new LogicException('Disabled email accepted'); }
    catch (RuntimeException $error) { assertContains('disabled', $error->getMessage()); }
});
test('disabled queue worker does not claim or mutate queued mail', function() {
    $queue=(new ReflectionClass(EmailQueueManager::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(EmailQueueManager::class,'emailConfig'))->setValue($queue,['enabled'=>false]);
    assertSameValue(['processed'=>0,'failed'=>0,'skipped'=>'email_disabled'],$queue->processQueue());
});
finishTests();
