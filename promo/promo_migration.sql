-- Schema changes for configurable promos (promo/*.php + the opt-in `apply_promo`
-- flag on POST /transaction/index.php). Run manually, once per schema, against
-- `raki_dev` first and `raki` at release time (the app reads the schema from
-- DB_SCHEMA in .env):
--
--   mysql -h <host> -u <user> -p raki_dev < promo/promo_migration.sql
--
-- Nothing here touches existing rows. `transaction.discount_amount` and
-- `transaction.voucher_id` already exist in both schemas, so they are not
-- re-created; promo discounts are written to `transaction.discount_amount`.
--
-- Deploy order: existing POS traffic never references these objects (the code
-- only reads/writes them when a request sends apply_promo = true), so the
-- migration can safely be applied before or after the code is deployed, but it
-- must be applied before any POS build starts sending apply_promo.

-- One row per promo rule. Everything a rule needs besides the multi-valued
-- conditions lives here. company_id is NOT NULL: promos are per-company.
CREATE TABLE `promo` (
  `promo_id` varchar(50) NOT NULL,
  `company_id` varchar(50) NOT NULL,
  `promo_name` varchar(255) NOT NULL,
  -- Only 'buy_n_nominal_off' exists today: every `buy_quantity` eligible items
  -- earn `discount_amount` rupiah off. Kept as a discriminator for future types.
  `promo_type` varchar(30) NOT NULL DEFAULT 'buy_n_nominal_off',
  `buy_quantity` int NOT NULL,
  `discount_amount` int NOT NULL,
  -- Cap on how many times the rule can fire in ONE transaction (NULL = unlimited).
  `max_applications` int DEFAULT NULL,
  -- Optional price conditions (NULL = not checked).
  `min_item_price` int DEFAULT NULL,
  `max_item_price` int DEFAULT NULL,
  `min_eligible_subtotal` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(50) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`promo_id`),
  KEY `idx_promo_company_active` (`company_id`, `is_active`),
  CONSTRAINT `fk_promo_company` FOREIGN KEY (`company_id`) REFERENCES `movira_core_dev`.`app_company` (`company_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Multi-valued conditions. A dimension with no rows is unrestricted.
--   day_of_week  '1'..'7' (ISO: 1 = Monday), judged on the sale's Jakarta date
--   category     category_menu.category_id   (item must be in one of these...)
--   menu         menu.menu_id                (...or be one of these)
--   exclude_menu menu.menu_id                (never eligible, wins over the two above)
--   outlet_type  app_role.role_name of the cashier (e.g. 'Outlet')
CREATE TABLE `promo_condition` (
  `promo_id` varchar(50) NOT NULL,
  `condition_type` enum('day_of_week','category','menu','exclude_menu','outlet_type') NOT NULL,
  `condition_value` varchar(100) NOT NULL,
  PRIMARY KEY (`promo_id`, `condition_type`, `condition_value`),
  CONSTRAINT `fk_promo_condition_promo` FOREIGN KEY (`promo_id`) REFERENCES `promo` (`promo_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Audit trail: which promo discounted which transaction. promo_name is a
-- snapshot and there is deliberately no FK to `promo`, so history survives a
-- promo being edited or deleted. Rows go away with their transaction, like
-- transaction_payment does.
CREATE TABLE `transaction_promo` (
  `transaction_promo_id` varchar(50) NOT NULL,
  `transaction_id` varchar(50) NOT NULL,
  `promo_id` varchar(50) NOT NULL,
  `promo_name` varchar(255) NOT NULL,
  `applications` int NOT NULL,
  `discount_amount` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`transaction_promo_id`),
  KEY `idx_transaction_promo_transaction` (`transaction_id`),
  KEY `idx_transaction_promo_promo` (`promo_id`),
  CONSTRAINT `fk_transaction_promo_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transaction` (`transaction_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Per-line share of the discount. Discounted transactions store NET amounts:
-- transaction.total_amount and transaction_detail.subtotal are already after
-- discount (so existing dashboards, cash reconciliation and payments stay
-- consistent), and gross line value = subtotal + discount_amount.
ALTER TABLE `transaction_detail`
  ADD COLUMN `discount_amount` int NOT NULL DEFAULT 0 AFTER `subtotal`;
