-- Transaksi (bundles, notes) and Setoran (dashboard docs/backend-endpoints-revamp.md §4, §5). Additive only.

ALTER TABLE transaction
  ADD COLUMN source VARCHAR(10) NULL,            -- 'bundle' = abang day bundle, 'outlet' = single sale; NULL = derive from source_type
  ADD COLUMN created_for VARCHAR(50) NULL,       -- abang the bundle is recorded under (created_by stays the Owner / Mitra)
  ADD COLUMN notes VARCHAR(500) NULL,
  ADD COLUMN sell_start CHAR(5) NULL,
  ADD COLUMN sell_end CHAR(5) NULL,
  ADD KEY idx_trx_company_date (company_id, transaction_date);

CREATE TABLE setoran_day (
  company_id VARCHAR(50) NOT NULL,
  setoran_date DATE NOT NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  finished_by VARCHAR(50) NULL,
  finished_at DATETIME NULL,
  qris_typed_total BIGINT NULL,
  PRIMARY KEY (company_id, setoran_date)
);

CREATE TABLE setoran_cash (
  company_id VARCHAR(50) NOT NULL,
  setoran_date DATE NOT NULL,
  point_id VARCHAR(50) NOT NULL,                 -- abang username, or 'outlet'
  reason VARCHAR(500) NULL,
  updated_by VARCHAR(50) NULL,
  PRIMARY KEY (company_id, setoran_date, point_id)
);

CREATE TABLE setoran_settlement (
  settlement_id VARCHAR(50) NOT NULL PRIMARY KEY,
  company_id VARCHAR(50) NOT NULL,
  setoran_date DATE NOT NULL,
  kind ENUM('qris','edc') NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  row_count INT NOT NULL,
  uploaded_by VARCHAR(50) NULL,
  uploaded_at DATETIME NOT NULL,
  KEY idx_settle (company_id, setoran_date, kind)
);

CREATE TABLE setoran_settlement_row (
  settlement_id VARCHAR(50) NOT NULL,
  row_no INT NOT NULL,
  amount INT NOT NULL,
  paid_time CHAR(5) NULL,                        -- HH:MM when the file has one
  PRIMARY KEY (settlement_id, row_no)
);

CREATE TABLE setoran_qris_flag (
  company_id VARCHAR(50) NOT NULL,
  transaction_id VARCHAR(50) NOT NULL,
  status ENUM('received','problem') NOT NULL,
  updated_by VARCHAR(50) NULL,
  PRIMARY KEY (company_id, transaction_id)
);
