<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../classes/services/NotificationService.php';
class NotificationRuntimeDatabase {
    public array $notifications=[];
    public function prepare($sql) { return new NotificationRuntimeStatement($this, $sql); }
}
class NotificationRuntimeStatement {
    private $db; private $sql;
    public function __construct($db,$sql) { $this->db=$db; $this->sql=$sql; }
    public function execute($params) {
        if (str_starts_with($this->sql,'INSERT INTO notifications')) $this->db->notifications[]=$params;
        return true;
    }
    public function fetch($mode) {
        if (str_contains($this->sql,'coop_integration_configuration')) return false;
        $row=array_fill_keys(['total','savingsAmount','savingsBalance','sharesAmount','sharesBalance','InterestPaid','loan','loanRepayment','loanBalance','CommodityRepayment','commodityBalance'], '0.00');
        return $row+['month'=>'Oct-2026','MobileNumber'=>'','onesignal_id'=>''];
    }
}
test('unconfigured SMS remains unavailable while member-scoped in-app notification persists',function(){
    $db=new NotificationRuntimeDatabase();
    $service=new App\Services\NotificationService($db);
    assertSameValue(true,$service->sendTransactionNotification('synthetic-member',1));
    assertSameValue(1,count($db->notifications));
    assertSameValue('synthetic-member',$db->notifications[0][':memberId']);
    assertSameValue('Transaction Update',$db->notifications[0][':title']);
});
test('all public SMS operations reject unavailable adapter before any network call',function(){
    $service=new App\Services\NotificationService(new NotificationRuntimeDatabase());
    foreach(['getSMSBalance','getSMSInbox','sendBulkSMS'] as $method){
        try { $method==='sendBulkSMS'?$service->$method(['synthetic'],'message'):$service->$method(); throw new LogicException('Unavailable SMS succeeded'); }
        catch(RuntimeException $error){assertContains('not implemented',$error->getMessage());}
    }
});
finishTests();
