-- Roll application code back first. Retain nonempty command rows for retry/audit evidence.
SET @command_rows = 0;
SET @commands_exist = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='coop_loan_product_commands');
SET @ddl = IF(@commands_exist>0, 'SELECT @command_rows := COUNT(*) FROM coop_loan_product_commands', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = IF(@command_rows=0, 'DROP TABLE IF EXISTS coop_loan_product_commands', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT IF(@command_rows=0, 'Empty command storage removed', 'Command storage retained for retry/audit evidence') AS rollback_result;
