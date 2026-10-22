<?php
require_once __DIR__.'/includes/session_helper.php';initSession();
require_once __DIR__.'/libs/services/CooperativeAccessService.php';
try{
    $actor=CooperativeAccessService::staffId($_SESSION['SESS_MEMBER_ID']??null);
    require_once __DIR__.'/config/EnvConfig.php';$c=EnvConfig::getDatabaseConfig();
    $db=new PDO('mysql:host='.$c['host'].';dbname='.$c['name'].';charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $access=new CooperativeAccessService($db);$access->userDirectory($actor,1,1);$canManage=$access->hasPermission($actor,'access.users.manage');
}catch(Throwable$e){http_response_code($e->getCode()===403?403:503);echo 'Cooperative user administration is unavailable or permission is required.';exit;}
header('Cache-Control: no-store');$pageTitle='Cooperative staff accounts';require __DIR__.'/includes/header.php';
?>
<section class="app-page" id="cooperative-users" data-actor="<?=htmlspecialchars((string)$actor,ENT_QUOTES,'UTF-8')?>">
<h1>Cooperative staff accounts</h1>
<p>Manage cooperative login accounts. Role permissions are configured in <a href="cooperative_access.php">cooperative access administration</a>. New accounts receive no capability grants.</p>
<div id="user-message" role="status" aria-live="polite"></div>
<div class="app-card"><h2>Staff directory</h2><button id="users-reload" type="button">Reload staff</button><table><thead><tr><th>Staff ID</th><th>Name</th><th>Username</th><th>Email</th><th>Status</th><th>Legacy role label</th></tr></thead><tbody id="users-body"></tbody></table><button id="users-prev" type="button">Previous</button><span id="users-page"></span><button id="users-next" type="button">Next</button></div>
<?php if($canManage): ?>
<div class="app-card"><h2>Account change</h2>
<p>Retry an unconfirmed change with the same details. Passwords are never stored in browser storage; after reload, re-enter the original password for a pending password command. Deactivation preserves account and audit history.</p>
<form id="user-command" autocomplete="off">
<label for="account-action">Action</label><select id="account-action" name="action" required><option value="">Choose an action</option><option value="create_staff_user">Create staff account</option><option value="update_staff_user">Update name and email</option><option value="set_staff_status">Set account status</option><option value="reset_staff_password">Reset password</option></select>
<label for="staff-id">Cooperative staff account ID</label><input id="staff-id" name="staff_user_id" inputmode="numeric" pattern="[1-9][0-9]*" required>
<div data-field="username"><label for="staff-username">Username</label><input id="staff-username" name="username" maxlength="64"></div>
<div data-field="complete_name"><label for="staff-name">Full name</label><input id="staff-name" name="complete_name" maxlength="150"></div>
<div data-field="email"><label for="staff-email">Email</label><input id="staff-email" type="email" name="email" maxlength="254"></div>
<div data-field="password"><label for="staff-password">Password (12 to 72 bytes)</label><input id="staff-password" type="password" name="password" autocomplete="new-password"></div>
<div data-field="status"><label for="staff-status">Account status</label><select id="staff-status" name="status"><option value="">Choose status</option><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
<button type="submit" id="user-save">Save account change</button><button type="button" id="user-new">Start a new change</button></form></div>
<?php endif; ?>
</section>
<script src="assets/js/cooperative-users.js" defer></script>
<?php require __DIR__.'/includes/footer.php'; ?>
