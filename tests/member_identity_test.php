<?php
/**
 * Tests for includes/member_identity_helper.php.
 *
 * Run from the coop_admin directory:  php tests/member_identity_test.php
 * Uses an in-memory SQLite database, so it never touches real member data.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/member_identity_helper.php';

function createMemberTable()
{
    $conn = new PDO('sqlite::memory:');
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec(
        'CREATE TABLE tblemployees (
            CoopID TEXT NOT NULL,
            StaffID INTEGER NOT NULL,
            Status TEXT,
            EmailAddress TEXT,
            PRIMARY KEY (CoopID, StaffID)
        )'
    );

    return $conn;
}

function addMember($conn, $coopId, $staffId, $status, $email = '')
{
    $stmt = $conn->prepare(
        'INSERT INTO tblemployees (CoopID, StaffID, Status, EmailAddress) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$coopId, $staffId, $status, $email]);
}

test('allows a returning member to reuse the Staff ID of their withdrawn record', function () {
    // Arrange
    $conn = createMemberTable();
    addMember($conn, 'COOP-00010', 6, 'In-Active', 'grace@example.com');

    // Act
    $conflict = findActiveIdentityConflict($conn, '6', 'grace@example.com');

    // Assert
    assertSameValue(null, $conflict);
});

test('rejects a Staff ID held by an active member and names that member', function () {
    $conn = createMemberTable();
    addMember($conn, 'COOP-00010', 6, 'Active', 'someone@example.com');

    $conflict = findActiveIdentityConflict($conn, '6', 'new@example.com');

    assertContains('Staff ID', $conflict);
    assertContains('COOP-00010', $conflict);
});

test('rejects a Staff ID when an active record exists alongside a withdrawn one', function () {
    $conn = createMemberTable();
    addMember($conn, 'COOP-00010', 6, 'In-Active');
    addMember($conn, 'COOP-00200', 6, 'Active');

    $conflict = findActiveIdentityConflict($conn, '6', '');

    assertContains('COOP-00200', $conflict);
});

test('ignores the member being edited when checking the Staff ID', function () {
    $conn = createMemberTable();
    addMember($conn, 'COOP-00010', 6, 'In-Active', 'grace@example.com');
    addMember($conn, 'COOP-00200', 6, 'Active', 'grace@example.com');

    $conflict = findActiveIdentityConflict($conn, '6', 'grace@example.com', 'COOP-00200');

    assertSameValue(null, $conflict);
});

test('rejects an email held by an active member and names that member', function () {
    $conn = createMemberTable();
    addMember($conn, 'COOP-00010', 6, 'Active', 'grace@example.com');

    $conflict = findActiveIdentityConflict($conn, '7', 'grace@example.com');

    assertContains('Email', $conflict);
    assertContains('COOP-00010', $conflict);
});

test('never treats a blank email as a conflict', function () {
    $conn = createMemberTable();
    addMember($conn, 'COOP-00010', 6, 'Active', '');

    $conflict = findActiveIdentityConflict($conn, '7', '');

    assertSameValue(null, $conflict);
});

test('never treats the legacy placeholder Staff ID 0 as a conflict', function () {
    $conn = createMemberTable();
    addMember($conn, 'COOP-00010', 0, 'Active');

    $conflict = findActiveIdentityConflict($conn, '0', '', 'COOP-00011');

    assertSameValue(null, $conflict);
});

test('member claims resolve CoopID to the unique active payroll identity', function () {
    $db = createMemberTable();
    addMember($db, 'COOP-42', 7, 'Active');
    addMember($db, 'OLD-42', 7, 'In-Active');
    assertSameValue('7', authenticatedMemberStaffId($db, ['user_id'=>'COOP-42'], '7'));
    foreach ([['user_id'=>'OLD-42'], ['user_id'=>'missing'], []] as $claims) {
        try { authenticatedMemberStaffId($db, $claims); throw new RuntimeException('Access accepted'); }
        catch (Exception $e) { assertSameValue(true, in_array($e->getCode(), [401,403], true)); }
    }
    foreach (['8', [], '7 OR 1=1'] as $target) {
        try { authenticatedMemberStaffId($db, ['user_id'=>'COOP-42'], $target); throw new RuntimeException('Access accepted'); }
        catch (Exception $e) { assertSameValue(403, $e->getCode()); }
    }
    addMember($db, 'DUPLICATE', 7, 'Active');
    try { authenticatedMemberStaffId($db, ['user_id'=>'COOP-42']); throw new RuntimeException('Ambiguity accepted'); }
    catch (Exception $e) { assertSameValue(403, $e->getCode()); }
});
test('profile fields reject SQL expressions, authority fields and structured values', function () {
    validateMemberProfileChanges(['NAME'=>'Synthetic member']);
    foreach ([['is_admin'=>1], ['NAME = NULL, is_admin'=>1], ['NAME'=>[]], []] as $changes) {
        try { validateMemberProfileChanges($changes); throw new RuntimeException('Invalid changes accepted'); }
        catch (Exception $e) { assertSameValue(400, $e->getCode()); }
    }
});
finishTests();
