-- Schema changes required for server-side voucher enforcement at transaction
-- creation time (transaction/index.php). Run manually against the `raki`
-- schema (see DB_SCHEMA in .env) before deploying this change.
--
-- `voucher_usage` already exists in the `raki` schema with this shape
-- (user_id = the creating user, no created_at column) — code in
-- transaction/index.php matches it as-is, nothing to run for it:
--
-- CREATE TABLE `voucher_usage` (
--   `usage_id` varchar(50) NOT NULL,
--   `voucher_id` varchar(50) NOT NULL,
--   `transaction_id` varchar(50) NOT NULL,
--   `user_id` varchar(50) NOT NULL,
--   `discount_amount` int NOT NULL,
--   `used_at` datetime DEFAULT CURRENT_TIMESTAMP,
--   PRIMARY KEY (`usage_id`),
--   KEY `voucher_id` (`voucher_id`),
--   KEY `transaction_id` (`transaction_id`),
--   CONSTRAINT `voucher_usage_ibfk_1` FOREIGN KEY (`voucher_id`) REFERENCES `voucher` (`voucher_id`),
--   CONSTRAINT `voucher_usage_ibfk_2` FOREIGN KEY (`transaction_id`) REFERENCES `transaction` (`transaction_id`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `raki`.`transaction`
  ADD COLUMN `voucher_id` VARCHAR(64) NULL AFTER `total_item`,
  ADD COLUMN `discount_amount` INT NOT NULL DEFAULT 0 AFTER `voucher_id`;
