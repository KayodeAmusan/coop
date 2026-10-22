<?php
/** Explicit connection checks only: SMTP connect/auth/quit and fixed Paystack GET /balance. */
class DeploymentConnectionTester{
 private $smtp;private $paystack;
 public function __construct(?callable $smtp=null,?callable $paystack=null){$this->smtp=$smtp??[$this,'smtpCheck'];$this->paystack=$paystack??[$this,'paystackCheck'];}
 public function test(string $provider,?array $runtime):array{
  if(!in_array($provider,['smtp','paystack','sms'],true))throw new InvalidArgumentException('Unsupported connection');
  if($provider==='sms')return['network_tested'=>false,'connected'=>false,'reason'=>'adapter_not_implemented'];
  if(!$runtime||!$runtime['enabled'])return['network_tested'=>false,'connected'=>false,'reason'=>'connection_not_enabled'];
  try{$connected=$provider==='smtp'?($this->smtp)($runtime['config'],$runtime['secrets']):($this->paystack)($runtime['config'],$runtime['secrets']);return['network_tested'=>true,'connected'=>$connected===true,'reason'=>$connected===true?'read_only_connection_verified':'connection_failed'];}
  catch(Throwable$error){return['network_tested'=>false,'connected'=>false,'reason'=>'connection_check_unavailable'];}
 }
 public function smtpCheck(array $config,array $secrets):bool{
  require_once __DIR__.'/../vendor/autoload.php';
  $host=$config['host'];$addresses=gethostbynamel($host);if(!$addresses)throw new RuntimeException('SMTP hostname could not be verified');
  foreach($addresses as$address)if(!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Private SMTP destinations require a controlled deployment adapter');
  $options=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host]];
  $smtp=new PHPMailer\PHPMailer\SMTP();$smtp->do_debug=0;$smtp->Timeout=8;$smtp->Timelimit=10;
  try{
   $target=($config['encryption']==='tls'?'ssl://':'').$addresses[0];if(!$smtp->connect($target,(int)$config['port'],8,$options))return false;
   if(!$smtp->hello('localhost'))return false;
   if($config['encryption']==='starttls'&&(!$smtp->startTLS()||!$smtp->hello('localhost')))return false;
   return$smtp->authenticate($config['username'],$secrets['password']??'');
  }finally{if($smtp->connected())$smtp->quit(true);$smtp->close();}
 }
 public function paystackCheck(array $config,array $secrets):bool{
  $secret=$secrets['secret_key']??'';if(!preg_match('/\Ask_'.preg_quote($config['mode']??'','/').'_[A-Za-z0-9]+\z/',$secret))throw new RuntimeException('Configured Paystack key does not match its mode');
  $request=curl_init('https://api.paystack.co/balance');$body='';
  curl_setopt_array($request,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret,'Accept: application/json'],CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($handle,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}]);
  try{$ok=curl_exec($request);$status=(int)curl_getinfo($request,CURLINFO_RESPONSE_CODE);$json=json_decode($body,true);return$ok!==false&&$status===200&&is_array($json)&&($json['status']??null)===true;}finally{curl_close($request);}
 }
}
