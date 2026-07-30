-- =====================================================================
-- LRMS migration: Central Bank of India "BC FIELD VISIT REPORT" fields
-- ---------------------------------------------------------------------
-- Run this ONCE on an installation that already has data. A brand new
-- install does not need it - schema.sql already contains these columns.
--
-- Import through cPanel > phpMyAdmin > your database > Import, or:
--     mysql -u cpuser_lrmsapp -p cpuser_lrms < database/migrate-visit-form.sql
--
-- SAFE TO RE-RUN. Each column is added through a helper procedure that
-- checks information_schema first, so importing twice changes nothing and
-- reports no errors.
--
-- Why a procedure instead of "ALTER TABLE ... ADD COLUMN IF NOT EXISTS":
-- that syntax is MariaDB only. MySQL 5.7 and 8.x reject it, and a shared
-- host may be running either. This works on all of them.
--
-- NOTHING IS DELETED OR RENAMED. Existing visits keep every value they
-- have; the new columns are simply NULL for them.
-- =====================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS lrms_add_column $$
CREATE PROCEDURE lrms_add_column(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_column
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `',
                          p_column, '` ', p_definition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DELIMITER ;

-- ---- 3. loan type ---------------------------------------------------
CALL lrms_add_column('visits', 'loan_type',
    "ENUM('ckcc','agl','dairy','shg','other') DEFAULT NULL");
CALL lrms_add_column('visits', 'loan_type_other',
    "VARCHAR(80) DEFAULT NULL");

-- ---- 4. current account status --------------------------------------
CALL lrms_add_column('visits', 'account_status',
    "ENUM('npa','ckcc_od2','krm_ots','other') DEFAULT NULL");
CALL lrms_add_column('visits', 'account_status_other',
    "VARCHAR(80) DEFAULT NULL");
CALL lrms_add_column('visits', 'rc_issued',
    "TINYINT(1) NOT NULL DEFAULT 0");

-- ---- 5. contact status ----------------------------------------------
CALL lrms_add_column('visits', 'contact_status',
    "ENUM('borrower','family','not_found','phone','phone_off') DEFAULT NULL");
CALL lrms_add_column('visits', 'contact_mobile_enc',
    "TEXT DEFAULT NULL");
CALL lrms_add_column('visits', 'contact_mobile_last4',
    "CHAR(4) DEFAULT NULL");

-- ---- 7. physical verification ---------------------------------------
CALL lrms_add_column('visits', 'borrower_alive',
    "TINYINT(1) DEFAULT NULL");
CALL lrms_add_column('visits', 'residence_status',
    "ENUM('same','moved') DEFAULT NULL");
CALL lrms_add_column('visits', 'income_source',
    "ENUM('agri','dairy','job','business','labour','other') DEFAULT NULL");
CALL lrms_add_column('visits', 'income_source_other',
    "VARCHAR(80) DEFAULT NULL");

-- ---- 9. willingness to pay ------------------------------------------
CALL lrms_add_column('visits', 'willing_to_pay',
    "TINYINT(1) DEFAULT NULL");
CALL lrms_add_column('visits', 'payment_plan',
    "ENUM('interest','krm_ots') DEFAULT NULL");

-- ---- 10 + 11. multi-select tick boxes -------------------------------
CALL lrms_add_column('visits', 'nonpayment_reasons',
    "VARCHAR(255) DEFAULT NULL");
CALL lrms_add_column('visits', 'nonpayment_other',
    "VARCHAR(120) DEFAULT NULL");
CALL lrms_add_column('visits', 'recommendations',
    "VARCHAR(255) DEFAULT NULL");

-- ---- 13. borrower signature -----------------------------------------
CALL lrms_add_column('visits', 'borrower_signature_path',
    "VARCHAR(255) DEFAULT NULL");

DROP PROCEDURE IF EXISTS lrms_add_column;

-- ---------------------------------------------------------------------
-- Verify: this should list all 18 new columns.
-- ---------------------------------------------------------------------
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'visits'
  AND COLUMN_NAME IN (
      'loan_type','loan_type_other','account_status','account_status_other',
      'rc_issued','contact_status','contact_mobile_enc','contact_mobile_last4',
      'borrower_alive','residence_status','income_source','income_source_other',
      'willing_to_pay','payment_plan','nonpayment_reasons','nonpayment_other',
      'recommendations','borrower_signature_path'
  )
ORDER BY ORDINAL_POSITION;
