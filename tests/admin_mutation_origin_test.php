<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../includes/admin_access_helper.php';
$server=['REQUEST_METHOD'=>'POST','HTTP_HOST'=>'coop.test:8443','HTTPS'=>'on','HTTP_ORIGIN'=>'https://coop.test:8443'];
test('accepts the same explicit origin',function()use($server){assertSameValue(null,findAdminMutationOriginError($server));});
foreach(['https://attacker.test:8443','https://coop.test.attacker.test:8443','http://coop.test:8443','https://coop.test','null',''] as $origin){
    test('rejects a cross-site, opaque, wrong-scheme, wrong-port or missing origin '.json_encode($origin),function()use($server,$origin){
        $server['HTTP_ORIGIN']=$origin;
        assertContains('origin',findAdminMutationOriginError($server));
    });
}
test('accepts exact origin from Referer when Origin is absent',function()use($server){
    unset($server['HTTP_ORIGIN']);$server['HTTP_REFERER']='https://coop.test:8443/path';
    assertSameValue(null,findAdminMutationOriginError($server));
});
test('accepts browser same-origin fetch metadata',function()use($server){
    unset($server['HTTP_ORIGIN']);$server['HTTP_SEC_FETCH_SITE']='same-origin';
    assertSameValue(null,findAdminMutationOriginError($server));
});
test('read-only methods do not require mutation headers',function()use($server){
    unset($server['HTTP_ORIGIN']);$server['REQUEST_METHOD']='GET';
    assertSameValue(null,findAdminMutationOriginError($server));
});
finishTests();
