-- log_request table for RAKI API
-- Stores one row per API request (success or failure)
-- Placed in movira_core schema (shared infrastructure)

CREATE TABLE IF NOT EXISTS `log_request` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`     CHAR(36)     NOT NULL COMMENT 'UUIDv7 generated server-side; echoed in X-Request-ID header',
  `client_request_id` VARCHAR(64) DEFAULT NULL COMMENT 'X-Request-ID sent by the client (may repeat on retries)',
  `timestamp`      DATETIME(3)  NOT NULL,
  `endpoint`       VARCHAR(255) NOT NULL COMMENT 'Script path, e.g. account/login.php',
  `http_method`    VARCHAR(10)  NOT NULL,
  `status_code`    SMALLINT UNSIGNED NOT NULL,
  `outcome`        ENUM('success','error') NOT NULL,
  `level`          ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
  `error_code`     VARCHAR(60)  DEFAULT NULL COMMENT 'ERR_* from ErrorCode catalog',
  `error_message`  VARCHAR(500) DEFAULT NULL COMMENT 'Message returned to the client',
  `retryable`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 for 4xx, 0 for 5xx',
  `username`       VARCHAR(100) DEFAULT NULL,
  `company_id`     VARCHAR(50)  DEFAULT NULL,
  `app_id`         VARCHAR(50)  DEFAULT NULL,
  `ip_address`     VARCHAR(45)  DEFAULT NULL,
  `user_agent`     VARCHAR(500) DEFAULT NULL,
  `duration_ms`    INT UNSIGNED DEFAULT NULL,
  `db_connected`   TINYINT(1)   NOT NULL DEFAULT 1,
  `exception_class`   VARCHAR(150) DEFAULT NULL,
  `exception_message` TEXT,
  `exception_file`    VARCHAR(255) DEFAULT NULL,
  `exception_line`    INT UNSIGNED DEFAULT NULL,
  `stack_trace`       MEDIUMTEXT,
  PRIMARY KEY (`id`),
  KEY `idx_lr_request_id` (`request_id`),
  KEY `idx_lr_outcome_ts` (`outcome`, `timestamp`),
  KEY `idx_lr_error_ts` (`error_code`, `timestamp`),
  KEY `idx_lr_username_ts` (`username`, `timestamp`),
  KEY `idx_lr_company_ts` (`company_id`, `timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
