-- =====================================================================
-- LRMS - Loan Recovery Management System
-- Database Schema (phpMyAdmin import-ready)
-- ---------------------------------------------------------------------
-- Target        : MySQL 5.7+ / MariaDB 10.4+  (cPanel shared hosting)
-- Charset       : utf8mb4 / utf8mb4_unicode_ci  (works on 5.7 AND 8.x;
--                 utf8mb4_0900_ai_ci is deliberately NOT used because it
--                 does not exist on MySQL 5.7 or MariaDB)
-- Engine        : InnoDB (foreign keys + transactions)
-- Rollback-safe : every statement uses IF NOT EXISTS / INSERT IGNORE, so
--                 re-importing this file will never destroy data.
--                 To tear everything down use database/rollback.sql
-- ---------------------------------------------------------------------
-- IMPORTANT about encryption:
--   Columns ending in `_enc` hold AES-256-CBC ciphertext (base64) produced
--   by backend/lib/Crypto.php. They are TEXT because ciphertext is longer
--   than plaintext. Never add an index on a `_enc` column.
--   Columns ending in `_hash` hold a HMAC-SHA256 (hex, 64 chars) of the
--   normalised plaintext. These ARE indexed and are what you search on
--   (exact match lookup of an encrypted value).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ---------------------------------------------------------------------
-- 1. roles
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(40)  NOT NULL COMMENT 'super_admin|regional_office|branch_manager|bc_agent',
  `name`        VARCHAR(80)  NOT NULL,
  `hierarchy`   TINYINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'lower = more powerful. 1=super admin',
  `description` VARCHAR(255) DEFAULT NULL,
  `is_system`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = cannot be deleted from UI',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. role_permissions  (fine-grained on top of the 4 base roles)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`        INT UNSIGNED NOT NULL,
  `permission_key` VARCHAR(80)  NOT NULL COMMENT 'e.g. customers.view, settings.manage',
  `allowed`        TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_perm` (`role_id`,`permission_key`),
  KEY `idx_rp_role` (`role_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. regions  (Regional Office scope)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `regions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(30)  NOT NULL,
  `name`       VARCHAR(120) NOT NULL,
  `state`      VARCHAR(80)  DEFAULT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_regions_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. branches
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `branches` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `region_id`     INT UNSIGNED DEFAULT NULL,
  `code`          VARCHAR(30)  NOT NULL COMMENT 'branch / solid code',
  `name`          VARCHAR(150) NOT NULL,
  `ifsc`          VARCHAR(15)  DEFAULT NULL,
  `district`      VARCHAR(80)  DEFAULT NULL,
  `block`         VARCHAR(80)  DEFAULT NULL,
  `address`       VARCHAR(255) DEFAULT NULL,
  `pincode`       VARCHAR(10)  DEFAULT NULL,
  `contact_phone` VARCHAR(20)  DEFAULT NULL,
  `latitude`      DECIMAL(10,7) DEFAULT NULL,
  `longitude`     DECIMAL(10,7) DEFAULT NULL,
  `geofence_m`    SMALLINT UNSIGNED NOT NULL DEFAULT 200 COMMENT 'attendance geofence radius in metres',
  `manager_id`    INT UNSIGNED DEFAULT NULL COMMENT 'users.id of branch manager',
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_code` (`code`),
  KEY `idx_branches_region` (`region_id`),
  KEY `idx_branches_district` (`district`),
  KEY `idx_branches_status` (`status`),
  CONSTRAINT `fk_branches_region` FOREIGN KEY (`region_id`) REFERENCES `regions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. users
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`               CHAR(36)     NOT NULL,
  `role_id`            INT UNSIGNED NOT NULL,
  `region_id`          INT UNSIGNED DEFAULT NULL,
  `branch_id`          INT UNSIGNED DEFAULT NULL,
  `employee_code`      VARCHAR(40)  DEFAULT NULL,
  `full_name`          VARCHAR(150) NOT NULL,
  `mobile_enc`         TEXT         DEFAULT NULL COMMENT 'AES-256-CBC of mobile',
  `mobile_hash`        CHAR(64)     DEFAULT NULL COMMENT 'HMAC-SHA256 for lookup',
  `mobile_last4`       CHAR(4)      DEFAULT NULL COMMENT 'safe to display',
  `email_enc`          TEXT         DEFAULT NULL,
  `email_hash`         CHAR(64)     DEFAULT NULL,
  `password_hash`      VARCHAR(255) DEFAULT NULL COMMENT 'password_hash() bcrypt/argon2; NULL = OTP-only user',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `photo_path`         VARCHAR(255) DEFAULT NULL,
  `status`             ENUM('pending','active','suspended','disabled') NOT NULL DEFAULT 'pending',
  `device_id`          VARCHAR(190) DEFAULT NULL COMMENT 'bound device (ANDROID_ID / installation uuid)',
  `device_model`       VARCHAR(120) DEFAULT NULL,
  `device_bound_at`    DATETIME     DEFAULT NULL,
  `failed_attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`       DATETIME     DEFAULT NULL,
  `last_login_at`      DATETIME     DEFAULT NULL,
  `last_login_ip`      VARCHAR(45)  DEFAULT NULL,
  `invitation_code_id` INT UNSIGNED DEFAULT NULL,
  `approved_by`        INT UNSIGNED DEFAULT NULL,
  `approved_at`        DATETIME     DEFAULT NULL,
  `created_by`         INT UNSIGNED DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_uuid` (`uuid`),
  UNIQUE KEY `uq_users_mobile_hash` (`mobile_hash`),
  UNIQUE KEY `uq_users_email_hash` (`email_hash`),
  UNIQUE KEY `uq_users_employee_code` (`employee_code`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_branch` (`branch_id`),
  KEY `idx_users_region` (`region_id`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_device` (`device_id`),
  CONSTRAINT `fk_users_role`   FOREIGN KEY (`role_id`)   REFERENCES `roles`(`id`),
  CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_region` FOREIGN KEY (`region_id`) REFERENCES `regions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. bc_agents  (extra profile for role bc_agent)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bc_agents` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `bc_code`        VARCHAR(40)  NOT NULL COMMENT 'matches BC CODE column in the Excel upload',
  `branch_id`      INT UNSIGNED DEFAULT NULL,
  `joining_date`   DATE         DEFAULT NULL,
  `monthly_target` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `visit_target`   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'target visits per day',
  `vehicle_no`     VARCHAR(30)  DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bc_user` (`user_id`),
  UNIQUE KEY `uq_bc_code` (`bc_code`),
  KEY `idx_bc_branch` (`branch_id`),
  CONSTRAINT `fk_bc_user`   FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bc_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. customers  (borrowers)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cif_number`      VARCHAR(50)  NOT NULL,
  `branch_id`       INT UNSIGNED DEFAULT NULL,
  `full_name`       VARCHAR(150) NOT NULL,
  `guardian_name`   VARCHAR(150) DEFAULT NULL COMMENT 'father / husband name',
  `gender`          ENUM('male','female','other') DEFAULT NULL,
  `date_of_birth`   DATE         DEFAULT NULL,
  `mobile_enc`      TEXT         DEFAULT NULL,
  `mobile_hash`     CHAR(64)     DEFAULT NULL,
  `mobile_last4`    CHAR(4)      DEFAULT NULL,
  `alt_mobile_enc`  TEXT         DEFAULT NULL,
  `alt_mobile_hash` CHAR(64)     DEFAULT NULL,
  `aadhaar_enc`     TEXT         DEFAULT NULL COMMENT 'never store plaintext',
  `aadhaar_last4`   CHAR(4)      DEFAULT NULL,
  `pan_enc`         TEXT         DEFAULT NULL,
  `address_line`    VARCHAR(255) DEFAULT NULL,
  `village`         VARCHAR(120) DEFAULT NULL,
  `panchayat`       VARCHAR(120) DEFAULT NULL,
  `block`           VARCHAR(120) DEFAULT NULL,
  `district`        VARCHAR(80)  DEFAULT NULL,
  `state`           VARCHAR(80)  DEFAULT NULL,
  `pincode`         VARCHAR(10)  DEFAULT NULL,
  `occupation`      VARCHAR(120) DEFAULT NULL,
  `latitude`        DECIMAL(10,7) DEFAULT NULL,
  `longitude`       DECIMAL(10,7) DEFAULT NULL,
  `status`          ENUM('active','closed','deceased','untraceable') NOT NULL DEFAULT 'active',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_cif` (`cif_number`),
  KEY `idx_customers_branch` (`branch_id`),
  KEY `idx_customers_mobile_hash` (`mobile_hash`),
  KEY `idx_customers_village` (`village`),
  KEY `idx_customers_district` (`district`),
  KEY `idx_customers_name` (`full_name`),
  CONSTRAINT `fk_customers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. allocation_batches  (one row per Excel upload)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `allocation_batches` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_uid`      CHAR(36)     NOT NULL,
  `file_name`      VARCHAR(255) NOT NULL,
  `stored_path`    VARCHAR(255) DEFAULT NULL,
  `strategy`       ENUM('bc_code','equal_branch','manual','none') NOT NULL DEFAULT 'bc_code',
  `total_rows`     INT UNSIGNED NOT NULL DEFAULT 0,
  `inserted_rows`  INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_rows`   INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_rows`   INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_rows`    INT UNSIGNED NOT NULL DEFAULT 0,
  `allocated_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_report`   VARCHAR(255) DEFAULT NULL COMMENT 'CSV of rejected rows',
  `status`         ENUM('processing','completed','failed','partial') NOT NULL DEFAULT 'processing',
  `message`        TEXT         DEFAULT NULL,
  `uploaded_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_uid` (`batch_uid`),
  KEY `idx_batch_user` (`uploaded_by`),
  CONSTRAINT `fk_batch_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. loans  (the recovery accounts)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loans` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_number`       VARCHAR(50)  NOT NULL,
  `customer_id`          INT UNSIGNED NOT NULL,
  `branch_id`            INT UNSIGNED DEFAULT NULL,
  `bc_id`                INT UNSIGNED DEFAULT NULL COMMENT 'bc_agents.id currently allocated',
  `product_name`         VARCHAR(120) DEFAULT NULL,
  `scheme_code`          VARCHAR(40)  DEFAULT NULL,
  `sanction_amount`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `disbursed_amount`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `outstanding_amount`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `overdue_amount`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `principal_overdue`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `interest_overdue`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `emi_amount`           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `disbursement_date`    DATE         DEFAULT NULL,
  `maturity_date`        DATE         DEFAULT NULL,
  `npa_date`             DATE         DEFAULT NULL,
  `asset_class`          ENUM('STD','SMA0','SMA1','SMA2','SS','DF1','DF2','DF3','LOSS') NOT NULL DEFAULT 'STD',
  `dpd`                  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'days past due',
  `last_paid_date`       DATE         DEFAULT NULL,
  `last_paid_amount`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_recovered`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `recovery_status`      ENUM('open','in_progress','promise','partly_paid','closed','ots','legal','write_off') NOT NULL DEFAULT 'open',
  `last_visit_at`        DATETIME     DEFAULT NULL,
  `visit_count`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `next_followup_date`   DATE         DEFAULT NULL,
  `allocation_batch_id`  INT UNSIGNED DEFAULT NULL,
  `allocated_at`         DATETIME     DEFAULT NULL,
  `risk_score`           TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100, filled by cron scoring job',
  `risk_band`            ENUM('low','medium','high','critical') DEFAULT NULL,
  `status`               ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loans_account` (`account_number`),
  KEY `idx_loans_customer` (`customer_id`),
  KEY `idx_loans_branch` (`branch_id`),
  KEY `idx_loans_bc` (`bc_id`),
  KEY `idx_loans_asset_class` (`asset_class`),
  KEY `idx_loans_recovery_status` (`recovery_status`),
  KEY `idx_loans_followup` (`next_followup_date`),
  KEY `idx_loans_batch` (`allocation_batch_id`),
  KEY `idx_loans_bc_status` (`bc_id`,`recovery_status`),
  KEY `idx_loans_branch_class` (`branch_id`,`asset_class`),
  CONSTRAINT `fk_loans_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_loans_branch`   FOREIGN KEY (`branch_id`)   REFERENCES `branches`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_loans_bc`       FOREIGN KEY (`bc_id`)       REFERENCES `bc_agents`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_loans_batch`    FOREIGN KEY (`allocation_batch_id`) REFERENCES `allocation_batches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. visits  (GPS verified field visit)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `visits` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_uid`           CHAR(36)     NOT NULL COMMENT 'generated on device, used for idempotent offline sync',
  `loan_id`             INT UNSIGNED NOT NULL,
  `customer_id`         INT UNSIGNED NOT NULL,
  `bc_id`               INT UNSIGNED DEFAULT NULL,
  `user_id`             INT UNSIGNED DEFAULT NULL COMMENT 'who submitted',
  `branch_id`           INT UNSIGNED DEFAULT NULL,
  `visit_date`          DATE         NOT NULL,
  `visited_at`          DATETIME     NOT NULL COMMENT 'device timestamp',
  `latitude`            DECIMAL(10,7) NOT NULL,
  `longitude`           DECIMAL(10,7) NOT NULL,
  `accuracy_m`          DECIMAL(7,2) DEFAULT NULL,
  `is_mock_location`    TINYINT(1)   NOT NULL DEFAULT 0,
  `distance_from_customer_m` INT UNSIGNED DEFAULT NULL,
  `resolved_address`    VARCHAR(255) DEFAULT NULL,
  `visit_status`        ENUM('visited','not_available','promise','paid','ots','legal','skip','untraceable') NOT NULL DEFAULT 'visited',
  `customer_available`  TINYINT(1)   NOT NULL DEFAULT 1,
  `house_locked`        TINYINT(1)   NOT NULL DEFAULT 0,
  `met_person`          VARCHAR(150) DEFAULT NULL,
  `met_relation`        VARCHAR(80)  DEFAULT NULL,
  `occupation`          VARCHAR(120) DEFAULT NULL,
  `recovery_possibility` ENUM('high','medium','low','nil') DEFAULT NULL,
  `promise_amount`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `promise_date`        DATE         DEFAULT NULL,
  `collected_amount`    DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'denormalised from recoveries for fast reports',
  `recommendation`      TEXT         DEFAULT NULL,
  `remarks`             TEXT         DEFAULT NULL,
  `signature_path`      VARCHAR(255) DEFAULT NULL,
  `device_id`           VARCHAR(190) DEFAULT NULL,
  `app_version`         VARCHAR(20)  DEFAULT NULL,
  `sync_source`         ENUM('online','offline_queue','web') NOT NULL DEFAULT 'online',
  `synced_at`           DATETIME     DEFAULT NULL,
  `verified_by`         INT UNSIGNED DEFAULT NULL,
  `verified_at`         DATETIME     DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visits_uid` (`visit_uid`),
  KEY `idx_visits_loan` (`loan_id`),
  KEY `idx_visits_customer` (`customer_id`),
  KEY `idx_visits_bc` (`bc_id`),
  KEY `idx_visits_branch` (`branch_id`),
  KEY `idx_visits_date` (`visit_date`),
  KEY `idx_visits_status` (`visit_status`),
  KEY `idx_visits_bc_date` (`bc_id`,`visit_date`),
  KEY `idx_visits_branch_date` (`branch_id`,`visit_date`),
  CONSTRAINT `fk_visits_loan`     FOREIGN KEY (`loan_id`)     REFERENCES `loans`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_visits_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visits_bc`       FOREIGN KEY (`bc_id`)       REFERENCES `bc_agents`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visits_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)     ON DELETE SET NULL,
  CONSTRAINT `fk_visits_branch`   FOREIGN KEY (`branch_id`)   REFERENCES `branches`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. visit_photos  (watermarked geo-tagged evidence)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `visit_photos` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_id`    INT UNSIGNED NOT NULL,
  `photo_uid`   CHAR(36)     NOT NULL,
  `file_path`   VARCHAR(255) NOT NULL COMMENT 'relative to uploads/',
  `thumb_path`  VARCHAR(255) DEFAULT NULL,
  `photo_type`  ENUM('house','customer','document','selfie','other') NOT NULL DEFAULT 'house',
  `latitude`    DECIMAL(10,7) DEFAULT NULL,
  `longitude`   DECIMAL(10,7) DEFAULT NULL,
  `captured_at` DATETIME     DEFAULT NULL,
  `watermarked` TINYINT(1)   NOT NULL DEFAULT 0,
  `file_hash`   CHAR(64)     DEFAULT NULL COMMENT 'sha256 - tamper detection',
  `size_bytes`  INT UNSIGNED NOT NULL DEFAULT 0,
  `mime_type`   VARCHAR(60)  DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_photo_uid` (`photo_uid`),
  KEY `idx_photos_visit` (`visit_id`),
  CONSTRAINT `fk_photos_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. recoveries
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `recoveries` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recovery_uid`   CHAR(36)     NOT NULL COMMENT 'device generated, idempotent sync',
  `receipt_number` VARCHAR(50)  NOT NULL,
  `loan_id`        INT UNSIGNED NOT NULL,
  `visit_id`       INT UNSIGNED DEFAULT NULL,
  `customer_id`    INT UNSIGNED NOT NULL,
  `bc_id`          INT UNSIGNED DEFAULT NULL,
  `branch_id`      INT UNSIGNED DEFAULT NULL,
  `amount`         DECIMAL(14,2) NOT NULL,
  `payment_mode`   ENUM('cash','transfer','upi','cheque','dd','other') NOT NULL DEFAULT 'cash',
  `txn_reference`  VARCHAR(120) DEFAULT NULL COMMENT 'UTR / UPI ref / cheque no',
  `bank_name`      VARCHAR(120) DEFAULT NULL,
  `collected_at`   DATETIME     NOT NULL,
  `deposited_at`   DATETIME     DEFAULT NULL,
  `latitude`       DECIMAL(10,7) DEFAULT NULL,
  `longitude`      DECIMAL(10,7) DEFAULT NULL,
  `receipt_photo`  VARCHAR(255) DEFAULT NULL,
  `status`         ENUM('pending','verified','rejected','reversed') NOT NULL DEFAULT 'pending',
  `verified_by`    INT UNSIGNED DEFAULT NULL,
  `verified_at`    DATETIME     DEFAULT NULL,
  `reject_reason`  VARCHAR(255) DEFAULT NULL,
  `remarks`        TEXT         DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recovery_uid` (`recovery_uid`),
  UNIQUE KEY `uq_receipt_number` (`receipt_number`),
  KEY `idx_rec_loan` (`loan_id`),
  KEY `idx_rec_visit` (`visit_id`),
  KEY `idx_rec_bc` (`bc_id`),
  KEY `idx_rec_branch` (`branch_id`),
  KEY `idx_rec_status` (`status`),
  KEY `idx_rec_collected` (`collected_at`),
  KEY `idx_rec_bc_date` (`bc_id`,`collected_at`),
  CONSTRAINT `fk_rec_loan`     FOREIGN KEY (`loan_id`)     REFERENCES `loans`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_rec_visit`    FOREIGN KEY (`visit_id`)    REFERENCES `visits`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_rec_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rec_bc`       FOREIGN KEY (`bc_id`)       REFERENCES `bc_agents`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rec_branch`   FOREIGN KEY (`branch_id`)   REFERENCES `branches`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. follow_ups
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `follow_ups` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_id`        INT UNSIGNED NOT NULL,
  `visit_id`       INT UNSIGNED DEFAULT NULL,
  `bc_id`          INT UNSIGNED DEFAULT NULL,
  `assigned_to`    INT UNSIGNED DEFAULT NULL COMMENT 'users.id',
  `due_date`       DATE         NOT NULL,
  `channel`        ENUM('visit','call','sms','push','none') NOT NULL DEFAULT 'visit',
  `promise_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `message`        VARCHAR(500) DEFAULT NULL,
  `status`         ENUM('pending','done','missed','cancelled') NOT NULL DEFAULT 'pending',
  `reminder_sent_at` DATETIME   DEFAULT NULL,
  `completed_at`   DATETIME     DEFAULT NULL,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fu_loan` (`loan_id`),
  KEY `idx_fu_due` (`due_date`,`status`),
  KEY `idx_fu_bc` (`bc_id`),
  KEY `idx_fu_assigned` (`assigned_to`),
  CONSTRAINT `fk_fu_loan`  FOREIGN KEY (`loan_id`)  REFERENCES `loans`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_fu_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_fu_bc`    FOREIGN KEY (`bc_id`)    REFERENCES `bc_agents`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. invitation_codes  (open signup is disabled)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invitation_codes` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`              VARCHAR(24)  NOT NULL,
  `role_id`           INT UNSIGNED NOT NULL,
  `branch_id`         INT UNSIGNED DEFAULT NULL,
  `region_id`         INT UNSIGNED DEFAULT NULL,
  `max_uses`          SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 = single use',
  `used_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `requires_approval` TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'if 1 the new user lands in status=pending',
  `expires_at`        DATETIME     DEFAULT NULL,
  `status`            ENUM('active','exhausted','expired','revoked') NOT NULL DEFAULT 'active',
  `notes`             VARCHAR(255) DEFAULT NULL,
  `created_by`        INT UNSIGNED DEFAULT NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invite_code` (`code`),
  KEY `idx_invite_status` (`status`),
  KEY `idx_invite_role` (`role_id`),
  CONSTRAINT `fk_invite_role`   FOREIGN KEY (`role_id`)   REFERENCES `roles`(`id`),
  CONSTRAINT `fk_invite_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invitation_redemptions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_id`     INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `redeemed_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_redeem_code` (`code_id`),
  CONSTRAINT `fk_redeem_code` FOREIGN KEY (`code_id`) REFERENCES `invitation_codes`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_redeem_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. settings  (everything configurable from Admin Panel, encrypted)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_key`     VARCHAR(40)  NOT NULL COMMENT 'smtp|sms|maps|firebase|app|invite|security|company',
  `setting_key`   VARCHAR(60)  NOT NULL,
  `setting_value` TEXT         DEFAULT NULL COMMENT 'AES ciphertext when is_encrypted=1',
  `is_encrypted`  TINYINT(1)   NOT NULL DEFAULT 0,
  `is_sensitive`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = never echo back to the browser',
  `value_type`    ENUM('string','int','bool','json','text') NOT NULL DEFAULT 'string',
  `label`         VARCHAR(120) DEFAULT NULL,
  `updated_by`    INT UNSIGNED DEFAULT NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting` (`group_key`,`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. otp_requests
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_requests` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier_hash` CHAR(64)     NOT NULL COMMENT 'HMAC of mobile/email - plaintext is never stored here',
  `identifier_type` ENUM('mobile','email') NOT NULL,
  `purpose`         ENUM('login','register','reset_password','device_reset','verify') NOT NULL DEFAULT 'login',
  `otp_hash`        VARCHAR(255) NOT NULL COMMENT 'password_hash of the OTP - OTP itself is never stored',
  `user_id`         INT UNSIGNED DEFAULT NULL,
  `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `expires_at`      DATETIME     NOT NULL,
  `consumed_at`     DATETIME     DEFAULT NULL,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  `delivery_status` ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  `delivery_error`  VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_lookup` (`identifier_hash`,`purpose`,`consumed_at`),
  KEY `idx_otp_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 17. attendance
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED NOT NULL,
  `branch_id`        INT UNSIGNED DEFAULT NULL,
  `attendance_date`  DATE         NOT NULL,
  `check_in_at`      DATETIME     DEFAULT NULL,
  `check_in_lat`     DECIMAL(10,7) DEFAULT NULL,
  `check_in_lng`     DECIMAL(10,7) DEFAULT NULL,
  `check_in_photo`   VARCHAR(255) DEFAULT NULL,
  `check_in_address` VARCHAR(255) DEFAULT NULL,
  `check_out_at`     DATETIME     DEFAULT NULL,
  `check_out_lat`    DECIMAL(10,7) DEFAULT NULL,
  `check_out_lng`    DECIMAL(10,7) DEFAULT NULL,
  `check_out_photo`  VARCHAR(255) DEFAULT NULL,
  `worked_minutes`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `distance_km`      DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `status`           ENUM('present','half_day','absent','leave','holiday') NOT NULL DEFAULT 'present',
  `is_outside_geofence` TINYINT(1) NOT NULL DEFAULT 0,
  `remarks`          VARCHAR(255) DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_user_date` (`user_id`,`attendance_date`),
  KEY `idx_att_date` (`attendance_date`),
  KEY `idx_att_branch` (`branch_id`),
  CONSTRAINT `fk_att_user`   FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_att_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18. gps_pings  (live tracking - plain GPS polling, no paid SDK)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gps_pings` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `latitude`    DECIMAL(10,7) NOT NULL,
  `longitude`   DECIMAL(10,7) NOT NULL,
  `accuracy_m`  DECIMAL(7,2) DEFAULT NULL,
  `speed_kmph`  DECIMAL(6,2) DEFAULT NULL,
  `battery_pct` TINYINT UNSIGNED DEFAULT NULL,
  `is_mock`     TINYINT(1)   NOT NULL DEFAULT 0,
  `recorded_at` DATETIME     NOT NULL COMMENT 'device time',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ping_user_time` (`user_id`,`recorded_at`),
  KEY `idx_ping_created` (`created_at`),
  CONSTRAINT `fk_ping_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 19. audit_logs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `actor_name`  VARCHAR(150) DEFAULT NULL COMMENT 'kept even if the user is deleted',
  `action`      VARCHAR(80)  NOT NULL COMMENT 'login.success, settings.update, visit.create ...',
  `entity_type` VARCHAR(60)  DEFAULT NULL,
  `entity_id`   VARCHAR(60)  DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `old_values`  TEXT         DEFAULT NULL COMMENT 'JSON, secrets are masked before writing',
  `new_values`  TEXT         DEFAULT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `channel`     ENUM('web','api','cron','cli') NOT NULL DEFAULT 'web',
  `severity`    ENUM('info','notice','warning','critical') NOT NULL DEFAULT 'info',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 20. api_tokens  (Android app sessions + device binding)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `token_hash`    CHAR(64)     NOT NULL COMMENT 'sha256 of the bearer token',
  `device_id`     VARCHAR(190) DEFAULT NULL,
  `device_model`  VARCHAR(120) DEFAULT NULL,
  `app_version`   VARCHAR(20)  DEFAULT NULL,
  `platform`      ENUM('android','ios','web') NOT NULL DEFAULT 'android',
  `ip_address`    VARCHAR(45)  DEFAULT NULL,
  `last_used_at`  DATETIME     DEFAULT NULL,
  `expires_at`    DATETIME     NOT NULL,
  `revoked_at`    DATETIME     DEFAULT NULL,
  `revoke_reason` VARCHAR(120) DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_token_user` (`user_id`),
  KEY `idx_token_expiry` (`expires_at`),
  CONSTRAINT `fk_token_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 21. user_devices  (history of device bindings + admin resets)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_devices` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `device_id`    VARCHAR(190) NOT NULL,
  `device_model` VARCHAR(120) DEFAULT NULL,
  `os_version`   VARCHAR(40)  DEFAULT NULL,
  `app_version`  VARCHAR(20)  DEFAULT NULL,
  `fcm_token`    VARCHAR(255) DEFAULT NULL,
  `status`       ENUM('active','released','blocked') NOT NULL DEFAULT 'active',
  `bound_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_at`  DATETIME     DEFAULT NULL,
  `released_by`  INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dev_user` (`user_id`),
  KEY `idx_dev_device` (`device_id`),
  CONSTRAINT `fk_dev_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 22. notifications  (in-app + FCM outbox)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED DEFAULT NULL COMMENT 'NULL = broadcast',
  `title`      VARCHAR(150) NOT NULL,
  `body`       VARCHAR(500) DEFAULT NULL,
  `data_json`  TEXT         DEFAULT NULL,
  `channel`    ENUM('push','sms','inapp') NOT NULL DEFAULT 'inapp',
  `status`     ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  `error`      VARCHAR(255) DEFAULT NULL,
  `sent_at`    DATETIME     DEFAULT NULL,
  `read_at`    DATETIME     DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`,`read_at`),
  KEY `idx_notif_status` (`status`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 23. message_logs  (SMS / email delivery audit - no plaintext secrets)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_logs` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel`         ENUM('sms','email','push') NOT NULL,
  `recipient_masked` VARCHAR(60) DEFAULT NULL COMMENT 'e.g. 98****3210 - never full value',
  `recipient_hash`  CHAR(64)    DEFAULT NULL,
  `template`        VARCHAR(60) DEFAULT NULL,
  `subject`         VARCHAR(190) DEFAULT NULL,
  `status`          ENUM('sent','failed','skipped') NOT NULL,
  `provider`        VARCHAR(60) DEFAULT NULL,
  `provider_ref`    VARCHAR(120) DEFAULT NULL,
  `error`           VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_channel` (`channel`,`status`),
  KEY `idx_msg_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 24. report_documents  (PDF + QR verification registry)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `report_documents` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_uid`       VARCHAR(40)  NOT NULL COMMENT 'printed on the PDF + encoded in the QR',
  `report_type`   VARCHAR(60)  NOT NULL COMMENT 'visit_report|recovery_statement|bc_performance ...',
  `entity_type`   VARCHAR(60)  DEFAULT NULL,
  `entity_id`     VARCHAR(60)  DEFAULT NULL,
  `params_json`   TEXT         DEFAULT NULL,
  `content_hash`  CHAR(64)     DEFAULT NULL COMMENT 'sha256 of the generated PDF',
  `file_path`     VARCHAR(255) DEFAULT NULL,
  `generated_by`  INT UNSIGNED DEFAULT NULL,
  `verify_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `last_verified_at` DATETIME  DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_uid` (`doc_uid`),
  KEY `idx_doc_type` (`report_type`),
  CONSTRAINT `fk_doc_user` FOREIGN KEY (`generated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 25. cron_runs  (so you can see from the panel whether cPanel cron fired)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cron_runs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job`         VARCHAR(60)  NOT NULL,
  `started_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME     DEFAULT NULL,
  `status`      ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  `processed`   INT UNSIGNED NOT NULL DEFAULT 0,
  `output`      TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cron_job` (`job`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 26. risk_scores  (phase-2 lightweight scoring, filled by cron)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `risk_scores` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_id`      INT UNSIGNED NOT NULL,
  `score`        TINYINT UNSIGNED NOT NULL COMMENT '0-100 recovery probability',
  `band`         ENUM('low','medium','high','critical') NOT NULL,
  `factors_json` TEXT         DEFAULT NULL COMMENT 'explainability: which rule contributed what',
  `suggestion`   VARCHAR(255) DEFAULT NULL COMMENT 'smart follow-up suggestion',
  `computed_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_risk_loan` (`loan_id`),
  KEY `idx_risk_band` (`band`),
  CONSTRAINT `fk_risk_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 27. login_attempts  (brute force throttling by ip + identifier)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier_hash` CHAR(64)    DEFAULT NULL,
  `ip_address`      VARCHAR(45) NOT NULL,
  `channel`         ENUM('web','api') NOT NULL DEFAULT 'web',
  `successful`      TINYINT(1)  NOT NULL DEFAULT 0,
  `reason`          VARCHAR(80) DEFAULT NULL,
  `created_at`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_la_ip` (`ip_address`,`created_at`),
  KEY `idx_la_ident` (`identifier_hash`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SEED DATA  (INSERT IGNORE => safe to re-run)
-- =====================================================================

INSERT IGNORE INTO `roles` (`id`,`code`,`name`,`hierarchy`,`description`,`is_system`) VALUES
  (1,'super_admin',     'Super Admin',     1,  'Full control of the system',                    1),
  (2,'regional_office', 'Regional Office', 10, 'Read access to all branches + all reports',     1),
  (3,'branch_manager',  'Branch Manager',  20, 'Manages own branch accounts and BC agents',     1),
  (4,'bc_agent',        'BC Agent',        30, 'Field agent: visits, GPS, recovery updates',    1);

-- Base permission matrix. Add/remove rows from the Admin Panel later.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_key`,`allowed`) VALUES
  (2,'dashboard.view',1),(2,'customers.view',1),(2,'loans.view',1),(2,'visits.view',1),
  (2,'recoveries.view',1),(2,'reports.view',1),(2,'reports.export',1),(2,'tracking.view',1),
  (2,'attendance.view',1),(2,'users.view',1),
  (3,'dashboard.view',1),(3,'customers.view',1),(3,'customers.edit',1),(3,'loans.view',1),
  (3,'loans.allocate',1),(3,'visits.view',1),(3,'visits.verify',1),(3,'recoveries.view',1),
  (3,'recoveries.verify',1),(3,'reports.view',1),(3,'reports.export',1),(3,'tracking.view',1),
  (3,'attendance.view',1),(3,'users.view',1),(3,'users.create',1),(3,'users.edit',1),
  (3,'users.approve',1),(3,'invites.create',1),(3,'followups.manage',1),(3,'branches.manage',1),
  (4,'app.login',1),(4,'visits.create',1),(4,'recoveries.create',1),(4,'attendance.mark',1),
  (4,'customers.view_assigned',1),(4,'followups.view_own',1);

-- Default settings. Values are intentionally EMPTY: fill them from
-- Admin Panel -> Settings / Integrations. Nothing is hardcoded in PHP.
-- is_encrypted=1 rows are AES encrypted by the application on save.
INSERT IGNORE INTO `settings` (`group_key`,`setting_key`,`setting_value`,`is_encrypted`,`is_sensitive`,`value_type`,`label`) VALUES
  ('company','app_name',        'LRMS',0,0,'string','Application Name'),
  ('company','organisation',    '',    0,0,'string','Bank / Organisation Name'),
  ('company','logo_path',       'assets/img/logo.svg',0,0,'string','Logo Path'),
  ('company','support_email',   '',    0,0,'string','Support Email'),
  ('company','support_phone',   '',    0,0,'string','Support Phone'),
  ('company','timezone',        'Asia/Kolkata',0,0,'string','Timezone'),

  ('smtp','host',       '',      0,0,'string','SMTP Host'),
  ('smtp','port',       '587',   0,0,'int',   'SMTP Port'),
  ('smtp','username',   '',      1,1,'string','SMTP Username'),
  ('smtp','password',   '',      1,1,'string','SMTP Password'),
  ('smtp','encryption', 'tls',   0,0,'string','Encryption (tls|ssl|none)'),
  ('smtp','from_email', '',      0,0,'string','From Email'),
  ('smtp','from_name',  'LRMS',  0,0,'string','From Name'),
  ('smtp','timeout',    '20',    0,0,'int',   'Socket Timeout (sec)'),

  ('sms','provider',    '',      0,0,'string','Provider Name'),
  ('sms','api_url',     '',      0,0,'text',  'API URL Template'),
  ('sms','api_key',     '',      1,1,'string','API Key'),
  ('sms','api_secret',  '',      1,1,'string','API Secret'),
  ('sms','sender_id',   '',      0,0,'string','Sender ID / Header'),
  ('sms','method',      'GET',   0,0,'string','HTTP Method (GET|POST|POST_JSON)'),
  ('sms','body_template','',     0,0,'text',  'POST Body Template (only for POST / POST_JSON)'),
  ('sms','dlt_template_id','',   0,0,'string','DLT Template ID'),
  ('sms','otp_template','Your LRMS OTP is {otp}. Valid for {minutes} minutes. Do not share.',0,0,'text','OTP SMS Template'),

  ('maps','api_key',    '',      1,1,'string','Google Maps API Key'),
  ('maps','default_lat','25.5941',0,0,'string','Default Map Latitude'),
  ('maps','default_lng','85.1376',0,0,'string','Default Map Longitude'),
  ('maps','default_zoom','7',    0,0,'int',   'Default Map Zoom'),

  ('firebase','server_key',      '',0,0,'string','Legacy FCM Server Key (deprecated by Google)'),
  ('firebase','service_account_json','',1,1,'text','Service Account JSON (FCM HTTP v1)'),
  ('firebase','project_id',      '',0,0,'string','Firebase Project ID'),

  ('app','app_version',      '1.0.0',0,0,'string','Latest App Version'),
  ('app','min_app_version',  '1.0.0',0,0,'string','Minimum Allowed App Version'),
  ('app','force_update',     '0',    0,0,'bool',  'Force Update'),
  ('app','maintenance_mode', '0',    0,0,'bool',  'Maintenance Mode'),
  ('app','maintenance_message','System under maintenance. Please try later.',0,0,'text','Maintenance Message'),
  ('app','apk_url',          '',     0,0,'string','APK Download URL'),
  ('app','gps_ping_interval','300',  0,0,'int',   'GPS Ping Interval (sec)'),
  ('app','visit_photo_min',  '1',    0,0,'int',   'Minimum Photos Per Visit'),
  ('app','visit_max_distance_m','500',0,0,'int',  'Max Allowed Distance From Customer (m, 0=off)'),

  ('invite','default_expiry_days','7',0,0,'int',   'Default Invitation Expiry (days)'),
  ('invite','default_role_id',    '4',0,0,'int',   'Default Invitation Role'),
  ('invite','requires_approval',  '1',0,0,'bool',  'New Users Need Admin Approval'),
  ('invite','code_length',        '10',0,0,'int',  'Invitation Code Length'),

  ('security','otp_length',        '6',   0,0,'int', 'OTP Length'),
  ('security','otp_expiry_minutes','10',  0,0,'int', 'OTP Expiry (minutes)'),
  ('security','otp_resend_seconds','60',  0,0,'int', 'OTP Resend Cooldown (sec)'),
  ('security','max_login_attempts','5',   0,0,'int', 'Max Login Attempts'),
  ('security','lockout_minutes',   '15',  0,0,'int', 'Lockout Duration (minutes)'),
  ('security','session_timeout_minutes','30',0,0,'int','Web Auto Logout (minutes)'),
  ('security','api_token_days',    '30',  0,0,'int', 'API Token Validity (days)'),
  ('security','device_binding',    '1',   0,0,'bool','Enforce 1 Account = 1 Device'),
  ('security','block_mock_gps',    '1',   0,0,'bool','Reject Mock/Fake GPS'),
  ('security','audit_retention_days','365',0,0,'int','Audit Log Retention (days)'),
  ('security','gps_retention_days','90',  0,0,'int', 'GPS Ping Retention (days)');

-- ---------------------------------------------------------------------
-- Bootstrap Super Admin
-- ---------------------------------------------------------------------
-- Login  : admin@lrms.local
-- Password: Admin@12345      <-- CHANGE IMMEDIATELY AFTER FIRST LOGIN
-- The hash below is a bcrypt hash of that password. `must_change_password`
-- is 1 so the panel forces a reset on first login.
-- mobile_enc / email_enc are NULL here because they must be produced with
-- YOUR app key. Run  `php cron/bootstrap_admin.php`  after setting APP_KEY
-- to fill the encrypted contact columns, or just edit the user in the panel.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `users`
  (`id`,`uuid`,`role_id`,`full_name`,`employee_code`,`password_hash`,`must_change_password`,`status`,`created_at`)
VALUES
  (1,'00000000-0000-4000-8000-000000000001',1,'Super Admin','ADMIN001',
   '$2y$10$zaA4TDVGjk9mCxp1Khxf4uEWpu1hNLsL6WD9nRR2rRXGPBKm4vNVO',1,'active',CURRENT_TIMESTAMP);

-- =====================================================================
-- Helpful reporting views (CREATE OR REPLACE = rollback safe)
-- =====================================================================

CREATE OR REPLACE VIEW `v_bc_daily_summary` AS
SELECT
  b.`id`                AS bc_id,
  b.`bc_code`           AS bc_code,
  u.`full_name`         AS bc_name,
  b.`branch_id`         AS branch_id,
  CURRENT_DATE          AS report_date,
  (SELECT COUNT(*) FROM `loans` l WHERE l.`bc_id` = b.`id` AND l.`status` = 'active') AS assigned_accounts,
  (SELECT COUNT(*) FROM `visits` v WHERE v.`bc_id` = b.`id` AND v.`visit_date` = CURRENT_DATE) AS visits_today,
  (SELECT COALESCE(SUM(r.`amount`),0) FROM `recoveries` r
     WHERE r.`bc_id` = b.`id` AND DATE(r.`collected_at`) = CURRENT_DATE AND r.`status` <> 'rejected') AS recovery_today,
  (SELECT COALESCE(SUM(r.`amount`),0) FROM `recoveries` r
     WHERE r.`bc_id` = b.`id`
       AND YEAR(r.`collected_at`) = YEAR(CURRENT_DATE)
       AND MONTH(r.`collected_at`) = MONTH(CURRENT_DATE)
       AND r.`status` <> 'rejected') AS recovery_month
FROM `bc_agents` b
JOIN `users` u ON u.`id` = b.`user_id`
WHERE b.`status` = 'active';

CREATE OR REPLACE VIEW `v_branch_summary` AS
SELECT
  br.`id`   AS branch_id,
  br.`code` AS branch_code,
  br.`name` AS branch_name,
  COUNT(DISTINCT l.`id`) AS total_accounts,
  COALESCE(SUM(l.`outstanding_amount`),0) AS total_outstanding,
  COALESCE(SUM(l.`overdue_amount`),0)     AS total_overdue,
  COALESCE(SUM(l.`total_recovered`),0)    AS total_recovered,
  SUM(CASE WHEN l.`asset_class` IN ('SS','DF1','DF2','DF3','LOSS') THEN 1 ELSE 0 END) AS npa_count
FROM `branches` br
LEFT JOIN `loans` l ON l.`branch_id` = br.`id` AND l.`status` = 'active'
GROUP BY br.`id`, br.`code`, br.`name`;

-- =====================================================================
-- END OF SCHEMA
-- =====================================================================
