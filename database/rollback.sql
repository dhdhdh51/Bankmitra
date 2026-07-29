-- =====================================================================
-- LRMS - full teardown / rollback script
-- ---------------------------------------------------------------------
-- WARNING: this DESTROYS ALL DATA. Take a backup first:
--   mysqldump -u USER -p DBNAME > backup_$(date +%F).sql
--
-- Drop order does not matter because FK checks are disabled, but the
-- order below is child-before-parent anyway so it also works with
-- FOREIGN_KEY_CHECKS=1.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS `v_bc_daily_summary`;
DROP VIEW IF EXISTS `v_branch_summary`;

DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `risk_scores`;
DROP TABLE IF EXISTS `cron_runs`;
DROP TABLE IF EXISTS `report_documents`;
DROP TABLE IF EXISTS `message_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `user_devices`;
DROP TABLE IF EXISTS `api_tokens`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `gps_pings`;
DROP TABLE IF EXISTS `attendance`;
DROP TABLE IF EXISTS `otp_requests`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `invitation_redemptions`;
DROP TABLE IF EXISTS `invitation_codes`;
DROP TABLE IF EXISTS `follow_ups`;
DROP TABLE IF EXISTS `recoveries`;
DROP TABLE IF EXISTS `visit_photos`;
DROP TABLE IF EXISTS `visits`;
DROP TABLE IF EXISTS `loans`;
DROP TABLE IF EXISTS `allocation_batches`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `bc_agents`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `branches`;
DROP TABLE IF EXISTS `regions`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `roles`;

SET FOREIGN_KEY_CHECKS = 1;
