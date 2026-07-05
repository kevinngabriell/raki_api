-- Schema changes required for server-side voucher enforcement at transaction
-- creation time (transaction/index.php). Run manually against the `raki`
-- schema (see DB_SCHEMA in .env) before deploying this change.

ALTER TABLE `raki`.`transaction`
  ADD COLUMN `voucher_id` VARCHAR(64) NULL AFTER `total_item`,
  ADD COLUMN `discount_amount` INT NOT NULL DEFAULT 0 AFTER `voucher_id`;

CREATE TABLE `raki`.`voucher_usage` (
  `usage_id` VARCHAR(64) NOT NULL,
  `voucher_id` VARCHAR(64) NOT NULL,
  `transaction_id` VARCHAR(64) NOT NULL,
  `company_id` VARCHAR(64) NOT NULL,
  `discount_amount` INT NOT NULL DEFAULT 0,
  `used_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`usage_id`),
  KEY `idx_voucher_usage_voucher_id` (`voucher_id`),
  KEY `idx_voucher_usage_transaction_id` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
