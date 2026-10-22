<?php
/**
 * Access rule for endpoints that change member or contribution data:
 * the caller must be logged in and hold an admin role.
 */

const ADMIN_ROLES = ['admin', 'administrator', 'superadmin', 'superadministrator'];

/**
 * Returns an error message when the session may not use an admin endpoint, or
 * null when access is allowed. Pass $_SESSION.
 *
 * Only known administrator roles are accepted; a role substring grants no access.
 */
function findAdminAccessError(array $session)
{
    $memberId = trim((string) ($session['SESS_MEMBER_ID'] ?? ''));

    if ($memberId === '') {
        return 'Unauthorized access: Session not found. Please login.';
    }

    $role = $session['role'] ?? $session['admin_type'] ?? '';
    $role = is_string($role) ? strtolower(preg_replace('/\s+/', '', trim($role))) : '';

    if (!in_array($role, ADMIN_ROLES, true)) {
        return 'Unauthorized access: Admin privileges required.';
    }

    return null;
}

/** Session mutations must originate from this application, even for valid admin cookies. */
function findAdminMutationOriginError(array $server)
{
    if (in_array(strtoupper($server['REQUEST_METHOD'] ?? 'GET'), ['GET','HEAD','OPTIONS'], true)) {
        return null;
    }
    $source = $server['HTTP_ORIGIN'] ?? $server['HTTP_REFERER'] ?? '';
    if ($source === '' && ($server['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin') return null;
    $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
    $expected = parse_url($scheme . '://' . ($server['HTTP_HOST'] ?? ''));
    $actual = is_string($source) ? parse_url($source) : false;
    if (!$expected || !$actual || !isset($expected['host'], $actual['host'], $actual['scheme']) ||
        strtolower($actual['scheme']) !== $scheme ||
        strtolower($actual['host']) !== strtolower($expected['host']) ||
        ($actual['port'] ?? ($scheme === 'https' ? 443 : 80)) !== ($expected['port'] ?? ($scheme === 'https' ? 443 : 80))) {
        return 'Request origin could not be verified. Reload this application and try again.';
    }
    return null;
}
