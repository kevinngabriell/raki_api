-- Voucher & promo revamp (dashboard docs/backend-endpoints-revamp.md §9). Additive only.
-- Outlets: no *_company rows = valid in every outlet. Channels: NULL = outlet and abang.

ALTER TABLE voucher
  ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN code_prefix VARCHAR(30) NULL,
  ADD COLUMN code_count INT NULL,
  ADD COLUMN channels VARCHAR(20) NULL;

CREATE TABLE voucher_company (
  voucher_id VARCHAR(50) NOT NULL,
  company_id VARCHAR(50) NOT NULL,
  PRIMARY KEY (voucher_id, company_id),
  KEY idx_vc_company (company_id)
);

CREATE TABLE voucher_scope (
  voucher_id VARCHAR(50) NOT NULL,
  scope_type ENUM('category','menu') NOT NULL,
  scope_id VARCHAR(50) NOT NULL,
  PRIMARY KEY (voucher_id, scope_type, scope_id)
);

-- Single-use codes of a "unique" batch (PREFIX-XXXX).
CREATE TABLE voucher_code (
  code VARCHAR(100) NOT NULL PRIMARY KEY,
  voucher_id VARCHAR(50) NOT NULL,
  used_at DATETIME NULL,
  used_by VARCHAR(50) NULL,
  transaction_id VARCHAR(50) NULL,
  KEY idx_vcode_voucher (voucher_id)
);

ALTER TABLE voucher_usage
  ADD COLUMN code VARCHAR(100) NULL,
  ADD COLUMN company_id VARCHAR(50) NULL,
  ADD COLUMN channel VARCHAR(10) NULL;

ALTER TABLE promo
  ADD COLUMN start_date DATE NULL,
  ADD COLUMN end_date DATE NULL,
  ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN channels VARCHAR(20) NULL;

CREATE TABLE promo_company (
  promo_id VARCHAR(50) NOT NULL,
  company_id VARCHAR(50) NOT NULL,
  PRIMARY KEY (promo_id, company_id),
  KEY idx_pc_company (company_id)
);

-- Existing rows keep their old meaning: valid only in the company that created them.
INSERT INTO voucher_company (voucher_id, company_id) SELECT voucher_id, company_id FROM voucher WHERE company_id IS NOT NULL;
INSERT INTO promo_company (promo_id, company_id) SELECT promo_id, company_id FROM promo WHERE company_id IS NOT NULL;
