<?php
/**
 * Member identity rules for tblemployees.
 *
 * A member who withdraws keeps their record, marked In-Active. If they rejoin
 * they are registered afresh under a new CoopID, so the same StaffID or email
 * can legitimately appear on several records. What must stay unique is the
 * ACTIVE holder: payroll uploads and the mobile login resolve a StaffID or email
 * to the active record.
 */

require_once __DIR__ . '/validation_helper.php';
require_once __DIR__ . '/loan_consent_helper.php';

/** Legacy payroll/profile identifiers are StaffID; member JWTs contain CoopID. */
function authenticatedMemberStaffId(PDO $db, $claims, $requested = null)
{
    $coopId = authenticatedLoanMember($claims);
    $stmt = $db->prepare("SELECT StaffID FROM tblemployees WHERE CoopID = ? AND Status = 'Active'");
    $stmt->execute([$coopId]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows) !== 1 || !ctype_digit((string)$rows[0]) || (int)$rows[0] <= 0) {
        throw new Exception('An active member with a payroll identifier is required', 403);
    }
    $staffId = (string)(int)$rows[0];
    $stmt = $db->prepare("SELECT CoopID FROM tblemployees WHERE StaffID = ? AND Status = 'Active'");
    $stmt->execute([$staffId]);
    $owners = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($owners) !== 1 || (string)$owners[0] !== $coopId) {
        throw new Exception('Member payroll identity requires administrator review', 403);
    }
    if ($requested !== null && ((!is_string($requested) && !is_int($requested))
        || !ctype_digit((string)$requested) || (string)(int)$requested !== $staffId)) {
        throw new Exception('You cannot access another member\'s records', 403);
    }
    return $staffId;
}

/** Only profile columns already exposed by the legacy profile API may be reviewed. */
function validateMemberProfileChanges($changes)
{
    $fields = ['PPNO','NAME','EMAIL','GENDER','EMPDATE','DOB','DOPA','DOC','LG_ORIGIN','S_ORIGIN','LEVE_APT'];
    if (!is_array($changes) || !$changes) throw new Exception('Profile changes are required', 400);
    foreach ($changes as $field => $value) {
        if (!in_array($field, $fields, true) || (!is_scalar($value) && $value !== null)) {
            throw new Exception('Unsupported profile field or value', 400);
        }
    }
}

/**
 * Returns the CoopID of the active member holding $value in $column, or null.
 * $column is never user input — callers pass a literal column name.
 */
function findActiveMemberCoopId($conn, $column, $value, $excludeCoopId = null)
{
    $sql = "SELECT CoopID FROM tblemployees WHERE {$column} = ? AND Status = ?";
    $params = [$value, EMPLOYEE_STATUS_ACTIVE];

    if ($excludeCoopId !== null) {
        $sql .= ' AND CoopID != ?';
        $params[] = $excludeCoopId;
    }

    $stmt = $conn->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $coopId = $stmt->fetchColumn();

    return $coopId === false ? null : $coopId;
}

/**
 * Returns an error message when another ACTIVE member already holds the StaffID
 * or email, or null when the record may be saved as active.
 * $excludeCoopId skips the member being edited or reactivated.
 *
 * StaffID 0 is the legacy "unassigned" placeholder shared by several members,
 * and a blank email means "not yet registered", so neither can conflict.
 */
function findActiveIdentityConflict($conn, $staffId, $email, $excludeCoopId = null)
{
    if ((int) $staffId > 0) {
        $holder = findActiveMemberCoopId($conn, 'StaffID', $staffId, $excludeCoopId);

        if ($holder !== null) {
            return "Staff ID already belongs to active member {$holder}. "
                . 'Set that record to In-Active first if the member is rejoining.';
        }
    }

    if ($email !== '') {
        $holder = findActiveMemberCoopId($conn, 'EmailAddress', $email, $excludeCoopId);

        if ($holder !== null) {
            return "Email address already belongs to active member {$holder}. "
                . 'Set that record to In-Active first if the member is rejoining.';
        }
    }

    return null;
}
