-- Roll application code back first. Preserve role, membership, bootstrap and retry evidence.
SET @coop_access_used = (SELECT COUNT(*) FROM coop_access_roles) + (SELECT COUNT(*) FROM coop_access_commands) + (SELECT COUNT(*) FROM coop_access_control WHERE bootstrapped_at IS NOT NULL);
SET @coop_access_sql = IF(@coop_access_used = 0, 'DROP TABLE IF EXISTS coop_access_role_permissions, coop_access_staff_roles, coop_access_commands, coop_access_control, coop_access_roles', 'SELECT 1');
PREPARE coop_access_rollback FROM @coop_access_sql;
EXECUTE coop_access_rollback;
DEALLOCATE PREPARE coop_access_rollback;
