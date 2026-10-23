-- =====================================================================
-- RAKI production sync - release feature/revamp-pos-v2 (POS v2 + dashboard revamp)
-- Brings the production schema to the schema the new code expects (raki_dev).
-- File: migrations/v1.2.0/2026-10-23_revamp-pos-v2_prod_sync.sql
--
-- HOW TO RUN
--   1. Backup:  mysqldump --single-transaction --routines --triggers raki > raki_pre_release.sql
--   2. Put the app in maintenance mode (old code must not write during the run).
--   3. In your SQL query editor open ONE query tab on the production database (USE raki).
--      Run the WHOLE file in that one tab so the session variables survive.
--   4. Read the result grids at the bottom: every problems value must be 0.
--      If a statement fails with PREFLIGHT_FAILED__<check> nothing has been changed.
--      Fix the data it names and run the file again. The file is safe to run twice.
--   5. Deploy the new code, run composer install, leave maintenance mode.
--   6. Run only the VERIFICATION section again after deploy.
--
-- Properties: additive only (no drop, no rename, no re-key of existing data), idempotent,
-- no DELIMITER, no procedures, no triggers, one statement per line ending.
-- Every change statement is skipped unless @go = 1, so a failed preflight stops all changes
-- even in a client that keeps running after an error.
-- The database in use is whatever USE selected. It must contain the raki tables.
-- =====================================================================

-- =====================================================================
-- STEP 0  PREFLIGHT (reads only, stops before any change)
-- =====================================================================
SET @go = 1;
SET @db = DATABASE();
-- Check 1: a database is selected and it holds the raki tables (not movira_core, not an empty schema).
SET @bad = (SELECT IF(@db IS NULL, 1, IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name IN ('transaction','menu','voucher','promo','package','category_menu','supply_order','voucher_usage','work_session')) <> 9, 1, 0)));
SET @go = IF(@bad > 0, 0, @go);
SET @s = IF(@bad > 0, 'SELECT * FROM PREFLIGHT_FAILED__wrong_database', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- Check 2: MySQL 8 or newer (window functions and JSON are used). MariaDB is not supported.
SET @bad = (SELECT (CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) < 8) + (VERSION() LIKE '%MariaDB%'));
SET @go = IF(@bad > 0, 0, @go);
SET @s = IF(@bad > 0, 'SELECT * FROM PREFLIGHT_FAILED__mysql_version', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- Check 3: a table this file creates must not already exist with a different shape (a half-applied or foreign version).
SET @bad = (SELECT ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'bonus_payout') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'bonus_payout' AND column_name IN ('company_id','username','week_start','amount','paid_on','paid_by','created_at')) <> 7) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_expense') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'finance_expense' AND column_name IN ('expense_id','company_id','category','amount','expense_date','note','recurring_id','period','created_by','created_at')) <> 10) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_klop') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'finance_klop' AND column_name IN ('company_id','account','data','connected_at','last_sync_at')) <> 5) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_recurring') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'finance_recurring' AND column_name IN ('recurring_id','company_id','category','amount','day_of_month','note','start_period','end_period','created_by')) <> 9) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_recurring_skip') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'finance_recurring_skip' AND column_name IN ('recurring_id','period')) <> 2) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_royalty_payment') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'finance_royalty_payment' AND column_name IN ('payment_id','company_id','period','amount','paid_on','method','recorded_by','created_at')) <> 8) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_royalty_rule') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'finance_royalty_rule' AND column_name IN ('company_id','type','percent','fixed','updated_by','updated_at')) <> 6) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'promo_company') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'promo_company' AND column_name IN ('promo_id','company_id')) <> 2) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'sales_target') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'sales_target' AND column_name IN ('company_id','daily_target','monthly_target','updated_by','updated_at')) <> 5) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'setoran_cash') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'setoran_cash' AND column_name IN ('company_id','setoran_date','point_id','reason','updated_by')) <> 5) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'setoran_day') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'setoran_day' AND column_name IN ('company_id','setoran_date','done','finished_by','finished_at','qris_typed_total')) <> 6) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'setoran_qris_flag') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'setoran_qris_flag' AND column_name IN ('company_id','transaction_id','status','updated_by')) <> 4) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'setoran_settlement') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'setoran_settlement' AND column_name IN ('settlement_id','company_id','setoran_date','kind','file_name','row_count','uploaded_by','uploaded_at')) <> 8) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'setoran_settlement_row') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'setoran_settlement_row' AND column_name IN ('settlement_id','row_no','amount','paid_time')) <> 4) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'supply_payment') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_payment' AND column_name IN ('payment_id','supply_order_id','amount','paid_on','method','proof_url','recorded_by','created_at')) <> 8) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'supply_stock_count') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_stock_count' AND column_name IN ('count_id','company_id','counted_at','counted_by')) <> 4) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'supply_stock_count_item') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_stock_count_item' AND column_name IN ('count_id','ingredient_id','qty')) <> 3) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_notification_settings') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'user_notification_settings' AND column_name IN ('id','username','general_notif','updated_at')) <> 4) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'voucher_code') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher_code' AND column_name IN ('code','voucher_id','used_at','used_by','transaction_id')) <> 5) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'voucher_company') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher_company' AND column_name IN ('voucher_id','company_id')) <> 2) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'voucher_scope') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher_scope' AND column_name IN ('voucher_id','scope_type','scope_id')) <> 3) + ((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'wa_recap_setting') = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'wa_recap_setting' AND column_name IN ('company_id','enabled','send_time','destination','last_sent_date','updated_by','updated_at')) <> 7));
SET @go = IF(@bad > 0, 0, @go);
SET @s = IF(@bad > 0, 'SELECT * FROM PREFLIGHT_FAILED__new_table_wrong_shape', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- Check 4: a column this file adds must not already exist with another type.
SET @bad = (SELECT (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'category_menu' AND column_name = 'sort_order' AND column_type <> 'int') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'menu' AND column_name = 'description' AND column_type <> 'text') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'menu' AND column_name = 'sell_outlet' AND column_type <> 'tinyint(1)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'menu' AND column_name = 'sell_abang' AND column_type <> 'tinyint(1)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'menu' AND column_name = 'sugar_ice' AND column_type <> 'tinyint(1)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'menu' AND column_name = 'is_archived' AND column_type <> 'tinyint(1)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'items' AND column_type <> 'json') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'sell_outlet' AND column_type <> 'tinyint(1)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'sell_abang' AND column_type <> 'tinyint(1)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'is_active' AND column_type <> 'tinyint(1)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'is_archived' AND column_type <> 'tinyint(1)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'available_from' AND column_type <> 'date') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'available_to' AND column_type <> 'date') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'available_days' AND column_type <> 'json') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'available_time_from' AND column_type <> 'char(5)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'available_time_to' AND column_type <> 'char(5)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'promo' AND column_name = 'start_date' AND column_type <> 'date') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'promo' AND column_name = 'end_date' AND column_type <> 'date') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'promo' AND column_name = 'is_archived' AND column_type <> 'tinyint(1)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'promo' AND column_name = 'channels' AND column_type <> 'varchar(20)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_order' AND column_name = 'processing_at' AND column_type <> 'datetime') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_order' AND column_name = 'shipped_at' AND column_type <> 'datetime') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_order' AND column_name = 'rejected_at' AND column_type <> 'datetime') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_order' AND column_name = 'cancelled_at' AND column_type <> 'datetime') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_order' AND column_name = 'reason' AND column_type <> 'varchar(500)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'transaction' AND column_name = 'source' AND column_type <> 'varchar(10)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'transaction' AND column_name = 'created_for' AND column_type <> 'varchar(50)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'transaction' AND column_name = 'notes' AND column_type <> 'varchar(500)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'transaction' AND column_name = 'sell_start' AND column_type <> 'char(5)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'transaction' AND column_name = 'sell_end' AND column_type <> 'char(5)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher' AND column_name = 'is_archived' AND column_type <> 'tinyint(1)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher' AND column_name = 'code_prefix' AND column_type <> 'varchar(30)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher' AND column_name = 'code_count' AND column_type <> 'int') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher' AND column_name = 'channels' AND column_type <> 'varchar(20)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher_usage' AND column_name = 'code' AND column_type <> 'varchar(100)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher_usage' AND column_name = 'company_id' AND column_type <> 'varchar(50)') + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher_usage' AND column_name = 'channel' AND column_type <> 'varchar(10)'));
SET @go = IF(@bad > 0, 0, @go);
SET @s = IF(@bad > 0, 'SELECT * FROM PREFLIGHT_FAILED__column_type_conflict', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- Check 5: no invalid (empty) enum values in the columns the new code reads (col + 0 = 0 means invalid).
SET @bad = (SELECT (SELECT COUNT(*) FROM `transaction` WHERE `payment_method` IS NOT NULL AND `payment_method` + 0 = 0) + (SELECT COUNT(*) FROM `transaction` WHERE `source_type` IS NOT NULL AND `source_type` + 0 = 0) + (SELECT COUNT(*) FROM `transaction_payment` WHERE `payment_method` IS NOT NULL AND `payment_method` + 0 = 0) + (SELECT COUNT(*) FROM `supply_order` WHERE `status` IS NOT NULL AND `status` + 0 = 0) + (SELECT COUNT(*) FROM `voucher` WHERE `discount_type` IS NOT NULL AND `discount_type` + 0 = 0) + (SELECT COUNT(*) FROM `voucher` WHERE `usage_type` IS NOT NULL AND `usage_type` + 0 = 0));
SET @go = IF(@bad > 0, 0, @go);
SET @s = IF(@bad > 0, 'SELECT * FROM PREFLIGHT_FAILED__invalid_enum_value', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- Check 6: ids used as backfill keys are unique (voucher, promo, category_menu).
SET @bad = (SELECT (SELECT COUNT(*) FROM (SELECT voucher_id FROM voucher GROUP BY voucher_id HAVING COUNT(*) > 1) a) + (SELECT COUNT(*) FROM (SELECT promo_id FROM promo GROUP BY promo_id HAVING COUNT(*) > 1) b) + (SELECT COUNT(*) FROM (SELECT category_id FROM category_menu GROUP BY category_id HAVING COUNT(*) > 1) c));
SET @go = IF(@bad > 0, 0, @go);
SET @s = IF(@bad > 0, 'SELECT * FROM PREFLIGHT_FAILED__duplicate_backfill_keys', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- Check 7: ids copied into voucher_company and promo_company (VARCHAR 50) fit.
SET @bad = (SELECT (SELECT COUNT(*) FROM voucher WHERE CHAR_LENGTH(voucher_id) > 50 OR CHAR_LENGTH(company_id) > 50) + (SELECT COUNT(*) FROM promo WHERE CHAR_LENGTH(promo_id) > 50 OR CHAR_LENGTH(company_id) > 50));
SET @go = IF(@bad > 0, 0, @go);
SET @s = IF(@bad > 0, 'SELECT * FROM PREFLIGHT_FAILED__value_too_long', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- Check 8: category_menu ids are not NULL (sort_order backfill joins on them).
SET @bad = (SELECT COUNT(*) FROM category_menu WHERE category_id IS NULL);
SET @go = IF(@bad > 0, 0, @go);
SET @s = IF(@bad > 0, 'SELECT * FROM PREFLIGHT_FAILED__null_backfill_order', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- Row counts before any change. Compared with the counts at the end (LOST ROWS check).
SET @b_api_error_log = (SELECT COUNT(*) FROM `api_error_log`);
SET @b_app_notification = (SELECT COUNT(*) FROM `app_notification`);
SET @b_bonus_schema = (SELECT COUNT(*) FROM `bonus_schema`);
SET @b_bonus_transaction = (SELECT COUNT(*) FROM `bonus_transaction`);
SET @b_category_menu = (SELECT COUNT(*) FROM `category_menu`);
SET @b_ingredient = (SELECT COUNT(*) FROM `ingredient`);
SET @b_ingredient_category = (SELECT COUNT(*) FROM `ingredient_category`);
SET @b_member_point_ledger = (SELECT COUNT(*) FROM `member_point_ledger`);
SET @b_menu = (SELECT COUNT(*) FROM `menu`);
SET @b_package = (SELECT COUNT(*) FROM `package`);
SET @b_package_menu = (SELECT COUNT(*) FROM `package_menu`);
SET @b_payment_callback_log = (SELECT COUNT(*) FROM `payment_callback_log`);
SET @b_payment_transaction = (SELECT COUNT(*) FROM `payment_transaction`);
SET @b_point_earning_rule = (SELECT COUNT(*) FROM `point_earning_rule`);
SET @b_pos_queue_counter = (SELECT COUNT(*) FROM `pos_queue_counter`);
SET @b_promo = (SELECT COUNT(*) FROM `promo`);
SET @b_promo_condition = (SELECT COUNT(*) FROM `promo_condition`);
SET @b_reward_catalog = (SELECT COUNT(*) FROM `reward_catalog`);
SET @b_reward_redemption = (SELECT COUNT(*) FROM `reward_redemption`);
SET @b_supplier = (SELECT COUNT(*) FROM `supplier`);
SET @b_supply_order = (SELECT COUNT(*) FROM `supply_order`);
SET @b_supply_order_detail = (SELECT COUNT(*) FROM `supply_order_detail`);
SET @b_transaction = (SELECT COUNT(*) FROM `transaction`);
SET @b_transaction_detail = (SELECT COUNT(*) FROM `transaction_detail`);
SET @b_transaction_payment = (SELECT COUNT(*) FROM `transaction_payment`);
SET @b_transaction_promo = (SELECT COUNT(*) FROM `transaction_promo`);
SET @b_unit_of_measurement = (SELECT COUNT(*) FROM `unit_of_measurement`);
SET @b_voucher = (SELECT COUNT(*) FROM `voucher`);
SET @b_voucher_usage = (SELECT COUNT(*) FROM `voucher_usage`);
SET @b_work_session = (SELECT COUNT(*) FROM `work_session`);
SET @b_work_session_close_pin = (SELECT COUNT(*) FROM `work_session_close_pin`);
SET @b_work_session_stock = (SELECT COUNT(*) FROM `work_session_stock`);

-- =====================================================================
-- STEP 1  SCHEMA (DDL generated from the raki_dev definitions)
-- =====================================================================
-- 1a. New tables
-- table bonus_payout
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'bonus_payout') = 0, 'CREATE TABLE `bonus_payout` ( `company_id` varchar(50) NOT NULL, `username` varchar(500) NOT NULL, `week_start` date NOT NULL, `amount` int NOT NULL, `paid_on` date NOT NULL, `paid_by` varchar(50) NOT NULL, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`company_id`,`username`(100),`week_start`), KEY `idx_bp_week` (`company_id`,`week_start`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table finance_expense
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_expense') = 0, 'CREATE TABLE `finance_expense` ( `expense_id` varchar(50) NOT NULL, `company_id` varchar(50) NOT NULL, `category` varchar(50) NOT NULL, `amount` int NOT NULL, `expense_date` date NOT NULL, `note` varchar(500) DEFAULT NULL, `recurring_id` varchar(50) DEFAULT NULL, `period` char(7) DEFAULT NULL, `created_by` varchar(50) DEFAULT NULL, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`expense_id`), UNIQUE KEY `uq_fe_recurring` (`recurring_id`,`period`), KEY `idx_fe_company_date` (`company_id`,`expense_date`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table finance_klop
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_klop') = 0, 'CREATE TABLE `finance_klop` ( `company_id` varchar(50) NOT NULL, `account` varchar(255) DEFAULT NULL, `data` varchar(255) DEFAULT NULL, `connected_at` datetime DEFAULT NULL, `last_sync_at` datetime DEFAULT NULL, PRIMARY KEY (`company_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table finance_recurring
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_recurring') = 0, 'CREATE TABLE `finance_recurring` ( `recurring_id` varchar(50) NOT NULL, `company_id` varchar(50) NOT NULL, `category` varchar(50) NOT NULL, `amount` int NOT NULL, `day_of_month` tinyint NOT NULL, `note` varchar(500) DEFAULT NULL, `start_period` char(7) NOT NULL, `end_period` char(7) DEFAULT NULL, `created_by` varchar(50) DEFAULT NULL, PRIMARY KEY (`recurring_id`), KEY `idx_fr_company` (`company_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table finance_recurring_skip
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_recurring_skip') = 0, 'CREATE TABLE `finance_recurring_skip` ( `recurring_id` varchar(50) NOT NULL, `period` char(7) NOT NULL, PRIMARY KEY (`recurring_id`,`period`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table finance_royalty_payment
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_royalty_payment') = 0, 'CREATE TABLE `finance_royalty_payment` ( `payment_id` varchar(50) NOT NULL, `company_id` varchar(50) NOT NULL, `period` char(7) NOT NULL, `amount` int NOT NULL, `paid_on` date NOT NULL, `method` enum(''tunai'',''transfer'') NOT NULL, `recorded_by` varchar(50) NOT NULL, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`payment_id`), KEY `idx_frp_company` (`company_id`,`period`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table finance_royalty_rule
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'finance_royalty_rule') = 0, 'CREATE TABLE `finance_royalty_rule` ( `company_id` varchar(50) NOT NULL, `type` enum(''pct'',''fix'',''both'') NOT NULL, `percent` decimal(5,2) NOT NULL DEFAULT ''0.00'', `fixed` int NOT NULL DEFAULT ''0'', `updated_by` varchar(50) DEFAULT NULL, `updated_at` datetime DEFAULT NULL, PRIMARY KEY (`company_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table promo_company
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'promo_company') = 0, 'CREATE TABLE `promo_company` ( `promo_id` varchar(50) NOT NULL, `company_id` varchar(50) NOT NULL, PRIMARY KEY (`promo_id`,`company_id`), KEY `idx_pc_company` (`company_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table sales_target
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'sales_target') = 0, 'CREATE TABLE `sales_target` ( `company_id` varchar(50) NOT NULL, `daily_target` bigint DEFAULT NULL, `monthly_target` bigint DEFAULT NULL, `updated_by` varchar(50) DEFAULT NULL, `updated_at` datetime DEFAULT NULL, PRIMARY KEY (`company_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table setoran_cash
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'setoran_cash') = 0, 'CREATE TABLE `setoran_cash` ( `company_id` varchar(50) NOT NULL, `setoran_date` date NOT NULL, `point_id` varchar(50) NOT NULL, `reason` varchar(500) DEFAULT NULL, `updated_by` varchar(50) DEFAULT NULL, PRIMARY KEY (`company_id`,`setoran_date`,`point_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table setoran_day
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'setoran_day') = 0, 'CREATE TABLE `setoran_day` ( `company_id` varchar(50) NOT NULL, `setoran_date` date NOT NULL, `done` tinyint(1) NOT NULL DEFAULT ''0'', `finished_by` varchar(50) DEFAULT NULL, `finished_at` datetime DEFAULT NULL, `qris_typed_total` bigint DEFAULT NULL, PRIMARY KEY (`company_id`,`setoran_date`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table setoran_qris_flag
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'setoran_qris_flag') = 0, 'CREATE TABLE `setoran_qris_flag` ( `company_id` varchar(50) NOT NULL, `transaction_id` varchar(50) NOT NULL, `status` enum(''received'',''problem'') NOT NULL, `updated_by` varchar(50) DEFAULT NULL, PRIMARY KEY (`company_id`,`transaction_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table setoran_settlement
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'setoran_settlement') = 0, 'CREATE TABLE `setoran_settlement` ( `settlement_id` varchar(50) NOT NULL, `company_id` varchar(50) NOT NULL, `setoran_date` date NOT NULL, `kind` enum(''qris'',''edc'') NOT NULL, `file_name` varchar(255) NOT NULL, `row_count` int NOT NULL, `uploaded_by` varchar(50) DEFAULT NULL, `uploaded_at` datetime NOT NULL, PRIMARY KEY (`settlement_id`), KEY `idx_settle` (`company_id`,`setoran_date`,`kind`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table setoran_settlement_row
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'setoran_settlement_row') = 0, 'CREATE TABLE `setoran_settlement_row` ( `settlement_id` varchar(50) NOT NULL, `row_no` int NOT NULL, `amount` int NOT NULL, `paid_time` char(5) DEFAULT NULL, PRIMARY KEY (`settlement_id`,`row_no`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table supply_payment
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'supply_payment') = 0, 'CREATE TABLE `supply_payment` ( `payment_id` varchar(50) NOT NULL, `supply_order_id` varchar(50) NOT NULL, `amount` int NOT NULL, `paid_on` date NOT NULL, `method` enum(''tunai'',''transfer'') NOT NULL, `proof_url` varchar(500) DEFAULT NULL, `recorded_by` varchar(50) NOT NULL, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`payment_id`), KEY `idx_sp_order` (`supply_order_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table supply_stock_count
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'supply_stock_count') = 0, 'CREATE TABLE `supply_stock_count` ( `count_id` varchar(50) NOT NULL, `company_id` varchar(50) NOT NULL, `counted_at` datetime NOT NULL, `counted_by` varchar(50) NOT NULL, PRIMARY KEY (`count_id`), KEY `idx_ssc_company` (`company_id`,`counted_at`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table supply_stock_count_item
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'supply_stock_count_item') = 0, 'CREATE TABLE `supply_stock_count_item` ( `count_id` varchar(50) NOT NULL, `ingredient_id` varchar(50) NOT NULL, `qty` decimal(12,2) NOT NULL, PRIMARY KEY (`count_id`,`ingredient_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table user_notification_settings
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_notification_settings') = 0, 'CREATE TABLE `user_notification_settings` ( `id` int NOT NULL AUTO_INCREMENT, `username` varchar(255) NOT NULL, `general_notif` tinyint(1) NOT NULL DEFAULT ''1'', `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`id`), UNIQUE KEY `username` (`username`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table voucher_code
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'voucher_code') = 0, 'CREATE TABLE `voucher_code` ( `code` varchar(100) NOT NULL, `voucher_id` varchar(50) NOT NULL, `used_at` datetime DEFAULT NULL, `used_by` varchar(50) DEFAULT NULL, `transaction_id` varchar(50) DEFAULT NULL, PRIMARY KEY (`code`), KEY `idx_vcode_voucher` (`voucher_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table voucher_company
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'voucher_company') = 0, 'CREATE TABLE `voucher_company` ( `voucher_id` varchar(50) NOT NULL, `company_id` varchar(50) NOT NULL, PRIMARY KEY (`voucher_id`,`company_id`), KEY `idx_vc_company` (`company_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table voucher_scope
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'voucher_scope') = 0, 'CREATE TABLE `voucher_scope` ( `voucher_id` varchar(50) NOT NULL, `scope_type` enum(''category'',''menu'') NOT NULL, `scope_id` varchar(50) NOT NULL, PRIMARY KEY (`voucher_id`,`scope_type`,`scope_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- table wa_recap_setting
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'wa_recap_setting') = 0, 'CREATE TABLE `wa_recap_setting` ( `company_id` varchar(50) NOT NULL, `enabled` tinyint(1) NOT NULL DEFAULT ''0'', `send_time` char(5) NOT NULL DEFAULT ''21:00'', `destination` varchar(30) DEFAULT NULL, `last_sent_date` date DEFAULT NULL, `updated_by` varchar(50) DEFAULT NULL, `updated_at` datetime DEFAULT NULL, PRIMARY KEY (`company_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- 1b. New columns on existing tables (appended in dev order)
-- category_menu.sort_order
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'category_menu' AND column_name = 'sort_order') = 0, 'ALTER TABLE `category_menu` ADD COLUMN `sort_order` int NOT NULL DEFAULT ''1000000''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- menu.description
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'menu' AND column_name = 'description') = 0, 'ALTER TABLE `menu` ADD COLUMN `description` text', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- menu.sell_outlet
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'menu' AND column_name = 'sell_outlet') = 0, 'ALTER TABLE `menu` ADD COLUMN `sell_outlet` tinyint(1) NOT NULL DEFAULT ''1''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- menu.sell_abang
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'menu' AND column_name = 'sell_abang') = 0, 'ALTER TABLE `menu` ADD COLUMN `sell_abang` tinyint(1) NOT NULL DEFAULT ''1''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- menu.sugar_ice
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'menu' AND column_name = 'sugar_ice') = 0, 'ALTER TABLE `menu` ADD COLUMN `sugar_ice` tinyint(1) NOT NULL DEFAULT ''1''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- menu.is_archived
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'menu' AND column_name = 'is_archived') = 0, 'ALTER TABLE `menu` ADD COLUMN `is_archived` tinyint(1) NOT NULL DEFAULT ''0''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- package.items
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'items') = 0, 'ALTER TABLE `package` ADD COLUMN `items` json DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- package.sell_outlet
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'sell_outlet') = 0, 'ALTER TABLE `package` ADD COLUMN `sell_outlet` tinyint(1) NOT NULL DEFAULT ''1''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- package.sell_abang
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'sell_abang') = 0, 'ALTER TABLE `package` ADD COLUMN `sell_abang` tinyint(1) NOT NULL DEFAULT ''1''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- package.is_active
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'is_active') = 0, 'ALTER TABLE `package` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT ''1''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- package.is_archived
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'is_archived') = 0, 'ALTER TABLE `package` ADD COLUMN `is_archived` tinyint(1) NOT NULL DEFAULT ''0''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- package.available_from
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'available_from') = 0, 'ALTER TABLE `package` ADD COLUMN `available_from` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- package.available_to
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'available_to') = 0, 'ALTER TABLE `package` ADD COLUMN `available_to` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- package.available_days
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'available_days') = 0, 'ALTER TABLE `package` ADD COLUMN `available_days` json DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- package.available_time_from
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'available_time_from') = 0, 'ALTER TABLE `package` ADD COLUMN `available_time_from` char(5) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- package.available_time_to
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'package' AND column_name = 'available_time_to') = 0, 'ALTER TABLE `package` ADD COLUMN `available_time_to` char(5) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- promo.start_date
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'promo' AND column_name = 'start_date') = 0, 'ALTER TABLE `promo` ADD COLUMN `start_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- promo.end_date
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'promo' AND column_name = 'end_date') = 0, 'ALTER TABLE `promo` ADD COLUMN `end_date` date DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- promo.is_archived
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'promo' AND column_name = 'is_archived') = 0, 'ALTER TABLE `promo` ADD COLUMN `is_archived` tinyint(1) NOT NULL DEFAULT ''0''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- promo.channels
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'promo' AND column_name = 'channels') = 0, 'ALTER TABLE `promo` ADD COLUMN `channels` varchar(20) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- supply_order.processing_at
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_order' AND column_name = 'processing_at') = 0, 'ALTER TABLE `supply_order` ADD COLUMN `processing_at` datetime DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- supply_order.shipped_at
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_order' AND column_name = 'shipped_at') = 0, 'ALTER TABLE `supply_order` ADD COLUMN `shipped_at` datetime DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- supply_order.rejected_at
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_order' AND column_name = 'rejected_at') = 0, 'ALTER TABLE `supply_order` ADD COLUMN `rejected_at` datetime DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- supply_order.cancelled_at
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_order' AND column_name = 'cancelled_at') = 0, 'ALTER TABLE `supply_order` ADD COLUMN `cancelled_at` datetime DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- supply_order.reason
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'supply_order' AND column_name = 'reason') = 0, 'ALTER TABLE `supply_order` ADD COLUMN `reason` varchar(500) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- transaction.source
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'transaction' AND column_name = 'source') = 0, 'ALTER TABLE `transaction` ADD COLUMN `source` varchar(10) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- transaction.created_for
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'transaction' AND column_name = 'created_for') = 0, 'ALTER TABLE `transaction` ADD COLUMN `created_for` varchar(50) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- transaction.notes
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'transaction' AND column_name = 'notes') = 0, 'ALTER TABLE `transaction` ADD COLUMN `notes` varchar(500) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- transaction.sell_start
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'transaction' AND column_name = 'sell_start') = 0, 'ALTER TABLE `transaction` ADD COLUMN `sell_start` char(5) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- transaction.sell_end
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'transaction' AND column_name = 'sell_end') = 0, 'ALTER TABLE `transaction` ADD COLUMN `sell_end` char(5) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- voucher.is_archived
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher' AND column_name = 'is_archived') = 0, 'ALTER TABLE `voucher` ADD COLUMN `is_archived` tinyint(1) NOT NULL DEFAULT ''0''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- voucher.code_prefix
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher' AND column_name = 'code_prefix') = 0, 'ALTER TABLE `voucher` ADD COLUMN `code_prefix` varchar(30) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- voucher.code_count
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher' AND column_name = 'code_count') = 0, 'ALTER TABLE `voucher` ADD COLUMN `code_count` int DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- voucher.channels
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher' AND column_name = 'channels') = 0, 'ALTER TABLE `voucher` ADD COLUMN `channels` varchar(20) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- voucher_usage.code
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher_usage' AND column_name = 'code') = 0, 'ALTER TABLE `voucher_usage` ADD COLUMN `code` varchar(100) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- voucher_usage.company_id
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher_usage' AND column_name = 'company_id') = 0, 'ALTER TABLE `voucher_usage` ADD COLUMN `company_id` varchar(50) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- voucher_usage.channel
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'voucher_usage' AND column_name = 'channel') = 0, 'ALTER TABLE `voucher_usage` ADD COLUMN `channel` varchar(10) DEFAULT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- 1c. New indexes on existing tables
-- transaction.idx_trx_company_date
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @db AND table_name = 'transaction' AND index_name = 'idx_trx_company_date') = 0, 'ALTER TABLE `transaction` ADD KEY `idx_trx_company_date` (`company_id`,`transaction_date`)', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- =====================================================================
-- STEP 2  BACKFILL (every statement guarded, safe to run twice)
-- =====================================================================
-- Existing vouchers and promos keep their old meaning: valid only in the company that created them.
-- Runs only while the target table is empty, so outlets set to all outlets later are not undone by a re-run.
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM voucher_company) = 0, 'INSERT INTO voucher_company (voucher_id, company_id) SELECT v.voucher_id, v.company_id FROM voucher v WHERE v.company_id IS NOT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM promo_company) = 0, 'INSERT INTO promo_company (promo_id, company_id) SELECT p.promo_id, p.company_id FROM promo p WHERE p.company_id IS NOT NULL', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- Sugar and ice used to follow: everything except Topping and Add-on. Runs only while no menu has sugar_ice = 0.
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM menu WHERE sugar_ice = 0) = 0, 'UPDATE menu m JOIN category_menu c ON c.category_id = m.category_id SET m.sugar_ice = 0 WHERE LOWER(c.category_name) REGEXP ''^(topping|add-?on)$''', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;
-- Category order = creation order. Runs only while every category still has the column default.
SET @s = IF(@go = 1 AND (SELECT COUNT(*) FROM category_menu WHERE sort_order <> 1000000) = 0, 'UPDATE category_menu c JOIN (SELECT category_id, ROW_NUMBER() OVER (ORDER BY created_at, category_id) AS rn FROM category_menu) o ON o.category_id = c.category_id SET c.sort_order = o.rn', 'DO 0');
PREPARE s FROM @s;
EXECUTE s;
DEALLOCATE PREPARE s;

-- =====================================================================
-- STEP 3  VERIFICATION (every problems value must be 0)
-- =====================================================================
SELECT IF(@go = 1, 'MIGRATION RAN', 'MIGRATION DID NOT RUN - see PREFLIGHT_FAILED error above') AS status;
SELECT 'missing_tables' AS check_name, COUNT(*) AS problems FROM (SELECT 'bonus_payout' AS t UNION ALL SELECT 'finance_expense' AS t UNION ALL SELECT 'finance_klop' AS t UNION ALL SELECT 'finance_recurring' AS t UNION ALL SELECT 'finance_recurring_skip' AS t UNION ALL SELECT 'finance_royalty_payment' AS t UNION ALL SELECT 'finance_royalty_rule' AS t UNION ALL SELECT 'promo_company' AS t UNION ALL SELECT 'sales_target' AS t UNION ALL SELECT 'setoran_cash' AS t UNION ALL SELECT 'setoran_day' AS t UNION ALL SELECT 'setoran_qris_flag' AS t UNION ALL SELECT 'setoran_settlement' AS t UNION ALL SELECT 'setoran_settlement_row' AS t UNION ALL SELECT 'supply_payment' AS t UNION ALL SELECT 'supply_stock_count' AS t UNION ALL SELECT 'supply_stock_count_item' AS t UNION ALL SELECT 'user_notification_settings' AS t UNION ALL SELECT 'voucher_code' AS t UNION ALL SELECT 'voucher_company' AS t UNION ALL SELECT 'voucher_scope' AS t UNION ALL SELECT 'wa_recap_setting' AS t) e WHERE NOT EXISTS (SELECT 1 FROM information_schema.tables i WHERE i.table_schema = @db AND i.table_name = e.t);
SELECT 'missing_or_changed_columns' AS check_name, COUNT(*) AS problems FROM (SELECT 'bonus_payout' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'bonus_payout' AS t, 'username' AS c, 'varchar(500)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'bonus_payout' AS t, 'week_start' AS c, 'date' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'bonus_payout' AS t, 'amount' AS c, 'int' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'bonus_payout' AS t, 'paid_on' AS c, 'date' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'bonus_payout' AS t, 'paid_by' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'bonus_payout' AS t, 'created_at' AS c, 'datetime' AS ty, 'NO' AS nu, 'CURRENT_TIMESTAMP' AS df UNION ALL SELECT 'finance_expense' AS t, 'expense_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_expense' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_expense' AS t, 'category' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_expense' AS t, 'amount' AS c, 'int' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_expense' AS t, 'expense_date' AS c, 'date' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_expense' AS t, 'note' AS c, 'varchar(500)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_expense' AS t, 'recurring_id' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_expense' AS t, 'period' AS c, 'char(7)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_expense' AS t, 'created_by' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_expense' AS t, 'created_at' AS c, 'datetime' AS ty, 'NO' AS nu, 'CURRENT_TIMESTAMP' AS df UNION ALL SELECT 'finance_klop' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_klop' AS t, 'account' AS c, 'varchar(255)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_klop' AS t, 'data' AS c, 'varchar(255)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_klop' AS t, 'connected_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_klop' AS t, 'last_sync_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring' AS t, 'recurring_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring' AS t, 'category' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring' AS t, 'amount' AS c, 'int' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring' AS t, 'day_of_month' AS c, 'tinyint' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring' AS t, 'note' AS c, 'varchar(500)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring' AS t, 'start_period' AS c, 'char(7)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring' AS t, 'end_period' AS c, 'char(7)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring' AS t, 'created_by' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring_skip' AS t, 'recurring_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_recurring_skip' AS t, 'period' AS c, 'char(7)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_payment' AS t, 'payment_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_payment' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_payment' AS t, 'period' AS c, 'char(7)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_payment' AS t, 'amount' AS c, 'int' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_payment' AS t, 'paid_on' AS c, 'date' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_payment' AS t, 'method' AS c, 'enum(''tunai'',''transfer'')' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_payment' AS t, 'recorded_by' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_payment' AS t, 'created_at' AS c, 'datetime' AS ty, 'NO' AS nu, 'CURRENT_TIMESTAMP' AS df UNION ALL SELECT 'finance_royalty_rule' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_rule' AS t, 'type' AS c, 'enum(''pct'',''fix'',''both'')' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_rule' AS t, 'percent' AS c, 'decimal(5,2)' AS ty, 'NO' AS nu, '0.00' AS df UNION ALL SELECT 'finance_royalty_rule' AS t, 'fixed' AS c, 'int' AS ty, 'NO' AS nu, '0' AS df UNION ALL SELECT 'finance_royalty_rule' AS t, 'updated_by' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'finance_royalty_rule' AS t, 'updated_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'promo_company' AS t, 'promo_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'promo_company' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'sales_target' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'sales_target' AS t, 'daily_target' AS c, 'bigint' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'sales_target' AS t, 'monthly_target' AS c, 'bigint' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'sales_target' AS t, 'updated_by' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'sales_target' AS t, 'updated_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_cash' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_cash' AS t, 'setoran_date' AS c, 'date' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_cash' AS t, 'point_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_cash' AS t, 'reason' AS c, 'varchar(500)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_cash' AS t, 'updated_by' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_day' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_day' AS t, 'setoran_date' AS c, 'date' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_day' AS t, 'done' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '0' AS df UNION ALL SELECT 'setoran_day' AS t, 'finished_by' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_day' AS t, 'finished_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_day' AS t, 'qris_typed_total' AS c, 'bigint' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_qris_flag' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_qris_flag' AS t, 'transaction_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_qris_flag' AS t, 'status' AS c, 'enum(''received'',''problem'')' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_qris_flag' AS t, 'updated_by' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement' AS t, 'settlement_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement' AS t, 'setoran_date' AS c, 'date' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement' AS t, 'kind' AS c, 'enum(''qris'',''edc'')' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement' AS t, 'file_name' AS c, 'varchar(255)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement' AS t, 'row_count' AS c, 'int' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement' AS t, 'uploaded_by' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement' AS t, 'uploaded_at' AS c, 'datetime' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement_row' AS t, 'settlement_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement_row' AS t, 'row_no' AS c, 'int' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement_row' AS t, 'amount' AS c, 'int' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'setoran_settlement_row' AS t, 'paid_time' AS c, 'char(5)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_payment' AS t, 'payment_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_payment' AS t, 'supply_order_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_payment' AS t, 'amount' AS c, 'int' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_payment' AS t, 'paid_on' AS c, 'date' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_payment' AS t, 'method' AS c, 'enum(''tunai'',''transfer'')' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_payment' AS t, 'proof_url' AS c, 'varchar(500)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_payment' AS t, 'recorded_by' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_payment' AS t, 'created_at' AS c, 'datetime' AS ty, 'NO' AS nu, 'CURRENT_TIMESTAMP' AS df UNION ALL SELECT 'supply_stock_count' AS t, 'count_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_stock_count' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_stock_count' AS t, 'counted_at' AS c, 'datetime' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_stock_count' AS t, 'counted_by' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_stock_count_item' AS t, 'count_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_stock_count_item' AS t, 'ingredient_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_stock_count_item' AS t, 'qty' AS c, 'decimal(12,2)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'user_notification_settings' AS t, 'id' AS c, 'int' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'user_notification_settings' AS t, 'username' AS c, 'varchar(255)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'user_notification_settings' AS t, 'general_notif' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '1' AS df UNION ALL SELECT 'user_notification_settings' AS t, 'updated_at' AS c, 'datetime' AS ty, 'NO' AS nu, 'CURRENT_TIMESTAMP' AS df UNION ALL SELECT 'voucher_code' AS t, 'code' AS c, 'varchar(100)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_code' AS t, 'voucher_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_code' AS t, 'used_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_code' AS t, 'used_by' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_code' AS t, 'transaction_id' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_company' AS t, 'voucher_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_company' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_scope' AS t, 'voucher_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_scope' AS t, 'scope_type' AS c, 'enum(''category'',''menu'')' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_scope' AS t, 'scope_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'wa_recap_setting' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'NO' AS nu, '<NULL>' AS df UNION ALL SELECT 'wa_recap_setting' AS t, 'enabled' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '0' AS df UNION ALL SELECT 'wa_recap_setting' AS t, 'send_time' AS c, 'char(5)' AS ty, 'NO' AS nu, '21:00' AS df UNION ALL SELECT 'wa_recap_setting' AS t, 'destination' AS c, 'varchar(30)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'wa_recap_setting' AS t, 'last_sent_date' AS c, 'date' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'wa_recap_setting' AS t, 'updated_by' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'wa_recap_setting' AS t, 'updated_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'category_menu' AS t, 'sort_order' AS c, 'int' AS ty, 'NO' AS nu, '1000000' AS df UNION ALL SELECT 'menu' AS t, 'description' AS c, 'text' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'menu' AS t, 'sell_outlet' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '1' AS df UNION ALL SELECT 'menu' AS t, 'sell_abang' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '1' AS df UNION ALL SELECT 'menu' AS t, 'sugar_ice' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '1' AS df UNION ALL SELECT 'menu' AS t, 'is_archived' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '0' AS df UNION ALL SELECT 'package' AS t, 'items' AS c, 'json' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'package' AS t, 'sell_outlet' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '1' AS df UNION ALL SELECT 'package' AS t, 'sell_abang' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '1' AS df UNION ALL SELECT 'package' AS t, 'is_active' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '1' AS df UNION ALL SELECT 'package' AS t, 'is_archived' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '0' AS df UNION ALL SELECT 'package' AS t, 'available_from' AS c, 'date' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'package' AS t, 'available_to' AS c, 'date' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'package' AS t, 'available_days' AS c, 'json' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'package' AS t, 'available_time_from' AS c, 'char(5)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'package' AS t, 'available_time_to' AS c, 'char(5)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'promo' AS t, 'start_date' AS c, 'date' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'promo' AS t, 'end_date' AS c, 'date' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'promo' AS t, 'is_archived' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '0' AS df UNION ALL SELECT 'promo' AS t, 'channels' AS c, 'varchar(20)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_order' AS t, 'processing_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_order' AS t, 'shipped_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_order' AS t, 'rejected_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_order' AS t, 'cancelled_at' AS c, 'datetime' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'supply_order' AS t, 'reason' AS c, 'varchar(500)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'transaction' AS t, 'source' AS c, 'varchar(10)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'transaction' AS t, 'created_for' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'transaction' AS t, 'notes' AS c, 'varchar(500)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'transaction' AS t, 'sell_start' AS c, 'char(5)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'transaction' AS t, 'sell_end' AS c, 'char(5)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher' AS t, 'is_archived' AS c, 'tinyint(1)' AS ty, 'NO' AS nu, '0' AS df UNION ALL SELECT 'voucher' AS t, 'code_prefix' AS c, 'varchar(30)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher' AS t, 'code_count' AS c, 'int' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher' AS t, 'channels' AS c, 'varchar(20)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_usage' AS t, 'code' AS c, 'varchar(100)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_usage' AS t, 'company_id' AS c, 'varchar(50)' AS ty, 'YES' AS nu, '<NULL>' AS df UNION ALL SELECT 'voucher_usage' AS t, 'channel' AS c, 'varchar(10)' AS ty, 'YES' AS nu, '<NULL>' AS df) e WHERE NOT EXISTS (SELECT 1 FROM information_schema.columns i WHERE i.table_schema = @db AND i.table_name = e.t AND i.column_name = e.c AND i.column_type = e.ty AND i.is_nullable = e.nu AND IFNULL(i.column_default, '<NULL>') = e.df);
SELECT 'missing_indexes' AS check_name, COUNT(*) AS problems FROM (SELECT 'transaction' AS t, 'idx_trx_company_date' AS ix) e WHERE NOT EXISTS (SELECT 1 FROM information_schema.statistics i WHERE i.table_schema = @db AND i.table_name = e.t AND i.index_name = e.ix);
SELECT 'invalid_enum_value' AS check_name, (SELECT COUNT(*) FROM `transaction` WHERE `payment_method` IS NOT NULL AND `payment_method` + 0 = 0) + (SELECT COUNT(*) FROM `transaction` WHERE `source_type` IS NOT NULL AND `source_type` + 0 = 0) + (SELECT COUNT(*) FROM `transaction_payment` WHERE `payment_method` IS NOT NULL AND `payment_method` + 0 = 0) + (SELECT COUNT(*) FROM `supply_order` WHERE `status` IS NOT NULL AND `status` + 0 = 0) + (SELECT COUNT(*) FROM `voucher` WHERE `discount_type` IS NOT NULL AND `discount_type` + 0 = 0) + (SELECT COUNT(*) FROM `voucher` WHERE `usage_type` IS NOT NULL AND `usage_type` + 0 = 0) AS problems;
SELECT 'leftover_preflight_tables' AS check_name, COUNT(*) AS problems FROM information_schema.tables WHERE table_schema = @db AND table_name LIKE 'PREFLIGHT\_FAILED\_\_%';
SELECT 'leftover_helper_objects' AS check_name, (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = @db AND routine_name <> 'GetPaymentMethodSummary') + (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = @db) AS problems;
-- The next three describe the state before deploy. After deploy owners may legitimately change them.
SELECT 'voucher_without_company_row' AS check_name, COUNT(*) AS problems FROM voucher v WHERE v.company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM voucher_company x WHERE x.voucher_id = v.voucher_id AND x.company_id = v.company_id);
SELECT 'promo_without_company_row' AS check_name, COUNT(*) AS problems FROM promo p WHERE p.company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM promo_company x WHERE x.promo_id = p.promo_id AND x.company_id = p.company_id);
SELECT 'category_without_sort_order' AS check_name, COUNT(*) AS problems FROM category_menu WHERE sort_order = 1000000;
SELECT 'topping_menu_with_sugar_ice' AS check_name, COUNT(*) AS problems FROM menu m JOIN category_menu c ON c.category_id = m.category_id WHERE LOWER(c.category_name) REGEXP '^(topping|add-?on)$' AND m.sugar_ice = 1;
-- Row counts of the existing tables: after must be at least before. lost_rows must be 0.
SELECT t AS table_name, before_rows, after_rows, GREATEST(before_rows - after_rows, 0) AS lost_rows FROM (SELECT 'api_error_log' AS t, @b_api_error_log AS before_rows, (SELECT COUNT(*) FROM `api_error_log`) AS after_rows UNION ALL SELECT 'app_notification' AS t, @b_app_notification AS before_rows, (SELECT COUNT(*) FROM `app_notification`) AS after_rows UNION ALL SELECT 'bonus_schema' AS t, @b_bonus_schema AS before_rows, (SELECT COUNT(*) FROM `bonus_schema`) AS after_rows UNION ALL SELECT 'bonus_transaction' AS t, @b_bonus_transaction AS before_rows, (SELECT COUNT(*) FROM `bonus_transaction`) AS after_rows UNION ALL SELECT 'category_menu' AS t, @b_category_menu AS before_rows, (SELECT COUNT(*) FROM `category_menu`) AS after_rows UNION ALL SELECT 'ingredient' AS t, @b_ingredient AS before_rows, (SELECT COUNT(*) FROM `ingredient`) AS after_rows UNION ALL SELECT 'ingredient_category' AS t, @b_ingredient_category AS before_rows, (SELECT COUNT(*) FROM `ingredient_category`) AS after_rows UNION ALL SELECT 'member_point_ledger' AS t, @b_member_point_ledger AS before_rows, (SELECT COUNT(*) FROM `member_point_ledger`) AS after_rows UNION ALL SELECT 'menu' AS t, @b_menu AS before_rows, (SELECT COUNT(*) FROM `menu`) AS after_rows UNION ALL SELECT 'package' AS t, @b_package AS before_rows, (SELECT COUNT(*) FROM `package`) AS after_rows UNION ALL SELECT 'package_menu' AS t, @b_package_menu AS before_rows, (SELECT COUNT(*) FROM `package_menu`) AS after_rows UNION ALL SELECT 'payment_callback_log' AS t, @b_payment_callback_log AS before_rows, (SELECT COUNT(*) FROM `payment_callback_log`) AS after_rows UNION ALL SELECT 'payment_transaction' AS t, @b_payment_transaction AS before_rows, (SELECT COUNT(*) FROM `payment_transaction`) AS after_rows UNION ALL SELECT 'point_earning_rule' AS t, @b_point_earning_rule AS before_rows, (SELECT COUNT(*) FROM `point_earning_rule`) AS after_rows UNION ALL SELECT 'pos_queue_counter' AS t, @b_pos_queue_counter AS before_rows, (SELECT COUNT(*) FROM `pos_queue_counter`) AS after_rows UNION ALL SELECT 'promo' AS t, @b_promo AS before_rows, (SELECT COUNT(*) FROM `promo`) AS after_rows UNION ALL SELECT 'promo_condition' AS t, @b_promo_condition AS before_rows, (SELECT COUNT(*) FROM `promo_condition`) AS after_rows UNION ALL SELECT 'reward_catalog' AS t, @b_reward_catalog AS before_rows, (SELECT COUNT(*) FROM `reward_catalog`) AS after_rows UNION ALL SELECT 'reward_redemption' AS t, @b_reward_redemption AS before_rows, (SELECT COUNT(*) FROM `reward_redemption`) AS after_rows UNION ALL SELECT 'supplier' AS t, @b_supplier AS before_rows, (SELECT COUNT(*) FROM `supplier`) AS after_rows UNION ALL SELECT 'supply_order' AS t, @b_supply_order AS before_rows, (SELECT COUNT(*) FROM `supply_order`) AS after_rows UNION ALL SELECT 'supply_order_detail' AS t, @b_supply_order_detail AS before_rows, (SELECT COUNT(*) FROM `supply_order_detail`) AS after_rows UNION ALL SELECT 'transaction' AS t, @b_transaction AS before_rows, (SELECT COUNT(*) FROM `transaction`) AS after_rows UNION ALL SELECT 'transaction_detail' AS t, @b_transaction_detail AS before_rows, (SELECT COUNT(*) FROM `transaction_detail`) AS after_rows UNION ALL SELECT 'transaction_payment' AS t, @b_transaction_payment AS before_rows, (SELECT COUNT(*) FROM `transaction_payment`) AS after_rows UNION ALL SELECT 'transaction_promo' AS t, @b_transaction_promo AS before_rows, (SELECT COUNT(*) FROM `transaction_promo`) AS after_rows UNION ALL SELECT 'unit_of_measurement' AS t, @b_unit_of_measurement AS before_rows, (SELECT COUNT(*) FROM `unit_of_measurement`) AS after_rows UNION ALL SELECT 'voucher' AS t, @b_voucher AS before_rows, (SELECT COUNT(*) FROM `voucher`) AS after_rows UNION ALL SELECT 'voucher_usage' AS t, @b_voucher_usage AS before_rows, (SELECT COUNT(*) FROM `voucher_usage`) AS after_rows UNION ALL SELECT 'work_session' AS t, @b_work_session AS before_rows, (SELECT COUNT(*) FROM `work_session`) AS after_rows UNION ALL SELECT 'work_session_close_pin' AS t, @b_work_session_close_pin AS before_rows, (SELECT COUNT(*) FROM `work_session_close_pin`) AS after_rows UNION ALL SELECT 'work_session_stock' AS t, @b_work_session_stock AS before_rows, (SELECT COUNT(*) FROM `work_session_stock`) AS after_rows) c ORDER BY lost_rows DESC, t;
SELECT 'new tables (row counts)' AS info, (SELECT COUNT(*) FROM `voucher_company`) AS voucher_company, (SELECT COUNT(*) FROM `promo_company`) AS promo_company;

-- =====================================================================
-- NOTES
-- =====================================================================
-- Decisions
--   * Compared raki (prod) with raki_dev (target) on 2026-10-23. Prod has 32 tables and dev has 54.
--   * 22 tables are created: bonus_payout, finance_expense, finance_klop, finance_recurring, finance_recurring_skip,
--     finance_royalty_payment, finance_royalty_rule, promo_company, sales_target, setoran_cash, setoran_day,
--     setoran_qris_flag, setoran_settlement, setoran_settlement_row, supply_payment, supply_stock_count,
--     supply_stock_count_item, user_notification_settings, voucher_code, voucher_company, voucher_scope, wa_recap_setting.
--   * 37 columns are added to menu, category_menu, package, promo, voucher, voucher_usage, supply_order and transaction.
--     All are NULL or NOT NULL with a default, so the old code keeps working while this file runs and until deploy.
--     One index is added: transaction.idx_trx_company_date.
--   * No existing column changes type, nullability, enum values or default. No table needs archiving (no v1 shape exists).
--   * No foreign key is added. Dev has none on the new tables and the FK set of the shared tables is identical.
--     For that reason SET FOREIGN_KEY_CHECKS is not used.
--   * No existing damage was found in prod: 0 invalid enum values, 0 duplicate redemption codes, 0 orphans, 0 NULLs in NOT NULL columns.
--     So nothing is repaired. Checks 5 and 6 only guard against damage that appears before you run the file.
--   * Backfills: voucher_company and promo_company from company_id, menu.sugar_ice = 0 for Topping and Add-on categories,
--     category_menu.sort_order by creation order. These are the statements of the repo files 003 and 005, made re-runnable.
--   * Not backfilled on purpose:
--       package.items stays NULL (the code treats NULL as a legacy package built from package_menu).
--       transaction.source stays NULL (setoran/day.php derives it for old rows).
--       setoran_day has no rows for past days, so past days show as not finished. Marking history as done would claim a
--       reconciliation that nobody did. Decide with the finance owner if old days should be closed.
--       supply_order timestamps stay NULL (prod has no supply orders today).
-- Not copied from dev, set these yourself after the run
--   * finance_klop (bank account link), finance_royalty_rule, finance_royalty_payment, finance_recurring, finance_expense,
--     sales_target, wa_recap_setting (destination phone number), setoran_* rows, bonus_payout, supply_payment,
--     supply_stock_count rows, voucher_code rows, user_notification_settings rows. All are created empty.
--   * Dev test rows in existing tables (for example api_error_log has 984 rows in dev and 55 in prod) are not touched.
--   * Secrets and hosts in .env (S3, WAHA, SMTP, JWT, CRON_SECRET) are not part of the database.
-- Intentionally not synced
--   * AUTO_INCREMENT counters and row data. The stored procedure GetPaymentMethodSummary is identical in both and is not touched.
-- Outside this file (movira_core is not covered here)
--   * The new code writes log_request and reads app_company, app_role, app_static_payment, app_user and otp_codes in the core schema.
--     Compare movira_core with movira_core_dev before deploy. This file does not touch it.
-- Code issues noticed
--   * migrations/001 to 009 are plain DDL and not re-runnable. This file replaces them for production.
--   * .env of this checkout has APP_ENV=production but points at the dev databases. Check the .env of the real server.
-- =====================================================================

-- =====================================================================
-- ROLLBACK (commented on purpose)
-- The real rollback is the backup of step 1. The statements below only undo the structure. Add a semicolon to the end of a line when you uncomment it.
-- They delete the data the new code wrote after deploy. Do not run them after go-live without a fresh backup.
-- =====================================================================
-- DROP TABLE IF EXISTS `wa_recap_setting`
-- DROP TABLE IF EXISTS `voucher_scope`
-- DROP TABLE IF EXISTS `voucher_company`
-- DROP TABLE IF EXISTS `voucher_code`
-- DROP TABLE IF EXISTS `user_notification_settings`
-- DROP TABLE IF EXISTS `supply_stock_count_item`
-- DROP TABLE IF EXISTS `supply_stock_count`
-- DROP TABLE IF EXISTS `supply_payment`
-- DROP TABLE IF EXISTS `setoran_settlement_row`
-- DROP TABLE IF EXISTS `setoran_settlement`
-- DROP TABLE IF EXISTS `setoran_qris_flag`
-- DROP TABLE IF EXISTS `setoran_day`
-- DROP TABLE IF EXISTS `setoran_cash`
-- DROP TABLE IF EXISTS `sales_target`
-- DROP TABLE IF EXISTS `promo_company`
-- DROP TABLE IF EXISTS `finance_royalty_rule`
-- DROP TABLE IF EXISTS `finance_royalty_payment`
-- DROP TABLE IF EXISTS `finance_recurring_skip`
-- DROP TABLE IF EXISTS `finance_recurring`
-- DROP TABLE IF EXISTS `finance_klop`
-- DROP TABLE IF EXISTS `finance_expense`
-- DROP TABLE IF EXISTS `bonus_payout`
-- ALTER TABLE `transaction` DROP INDEX `idx_trx_company_date`
-- ALTER TABLE `voucher_usage` DROP COLUMN `channel`
-- ALTER TABLE `voucher_usage` DROP COLUMN `company_id`
-- ALTER TABLE `voucher_usage` DROP COLUMN `code`
-- ALTER TABLE `voucher` DROP COLUMN `channels`
-- ALTER TABLE `voucher` DROP COLUMN `code_count`
-- ALTER TABLE `voucher` DROP COLUMN `code_prefix`
-- ALTER TABLE `voucher` DROP COLUMN `is_archived`
-- ALTER TABLE `transaction` DROP COLUMN `sell_end`
-- ALTER TABLE `transaction` DROP COLUMN `sell_start`
-- ALTER TABLE `transaction` DROP COLUMN `notes`
-- ALTER TABLE `transaction` DROP COLUMN `created_for`
-- ALTER TABLE `transaction` DROP COLUMN `source`
-- ALTER TABLE `supply_order` DROP COLUMN `reason`
-- ALTER TABLE `supply_order` DROP COLUMN `cancelled_at`
-- ALTER TABLE `supply_order` DROP COLUMN `rejected_at`
-- ALTER TABLE `supply_order` DROP COLUMN `shipped_at`
-- ALTER TABLE `supply_order` DROP COLUMN `processing_at`
-- ALTER TABLE `promo` DROP COLUMN `channels`
-- ALTER TABLE `promo` DROP COLUMN `is_archived`
-- ALTER TABLE `promo` DROP COLUMN `end_date`
-- ALTER TABLE `promo` DROP COLUMN `start_date`
-- ALTER TABLE `package` DROP COLUMN `available_time_to`
-- ALTER TABLE `package` DROP COLUMN `available_time_from`
-- ALTER TABLE `package` DROP COLUMN `available_days`
-- ALTER TABLE `package` DROP COLUMN `available_to`
-- ALTER TABLE `package` DROP COLUMN `available_from`
-- ALTER TABLE `package` DROP COLUMN `is_archived`
-- ALTER TABLE `package` DROP COLUMN `is_active`
-- ALTER TABLE `package` DROP COLUMN `sell_abang`
-- ALTER TABLE `package` DROP COLUMN `sell_outlet`
-- ALTER TABLE `package` DROP COLUMN `items`
-- ALTER TABLE `menu` DROP COLUMN `is_archived`
-- ALTER TABLE `menu` DROP COLUMN `sugar_ice`
-- ALTER TABLE `menu` DROP COLUMN `sell_abang`
-- ALTER TABLE `menu` DROP COLUMN `sell_outlet`
-- ALTER TABLE `menu` DROP COLUMN `description`
-- ALTER TABLE `category_menu` DROP COLUMN `sort_order`
