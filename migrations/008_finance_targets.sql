-- Keuangan and sales targets (dashboard docs/backend-endpoints-revamp.md §11, §12). Additive only.

CREATE TABLE sales_target (
  company_id VARCHAR(50) NOT NULL PRIMARY KEY,
  daily_target BIGINT NULL,
  monthly_target BIGINT NULL,
  updated_by VARCHAR(50) NULL,
  updated_at DATETIME NULL
);

-- Manual biaya. company_id may be 'pusat' (the Owner's own costs).
CREATE TABLE finance_recurring (
  recurring_id VARCHAR(50) NOT NULL PRIMARY KEY,
  company_id VARCHAR(50) NOT NULL,
  category VARCHAR(50) NOT NULL,
  amount INT NOT NULL,
  day_of_month TINYINT NOT NULL,                  -- 1..31, clamped to the last day of shorter months
  note VARCHAR(500) NULL,
  start_period CHAR(7) NOT NULL,                  -- YYYY-MM, first month it applies
  end_period CHAR(7) NULL,                        -- last month it applies; NULL = until stopped
  created_by VARCHAR(50) NULL,
  KEY idx_fr_company (company_id)
);
CREATE TABLE finance_expense (
  expense_id VARCHAR(50) NOT NULL PRIMARY KEY,
  company_id VARCHAR(50) NOT NULL,
  category VARCHAR(50) NOT NULL,
  amount INT NOT NULL,
  expense_date DATE NOT NULL,
  note VARCHAR(500) NULL,
  recurring_id VARCHAR(50) NULL,
  period CHAR(7) NULL,                            -- month a recurring row was generated for
  created_by VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fe_recurring (recurring_id, period),
  KEY idx_fe_company_date (company_id, expense_date)
);
-- A recurring biaya deleted for one month only must not be generated again.
CREATE TABLE finance_recurring_skip (
  recurring_id VARCHAR(50) NOT NULL,
  period CHAR(7) NOT NULL,
  PRIMARY KEY (recurring_id, period)
);

CREATE TABLE finance_royalty_rule (
  company_id VARCHAR(50) NOT NULL PRIMARY KEY,    -- the Mitra outlet
  type ENUM('pct','fix','both') NOT NULL,
  percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  fixed INT NOT NULL DEFAULT 0,                   -- rupiah per month
  updated_by VARCHAR(50) NULL,
  updated_at DATETIME NULL
);
CREATE TABLE finance_royalty_payment (
  payment_id VARCHAR(50) NOT NULL PRIMARY KEY,
  company_id VARCHAR(50) NOT NULL,
  period CHAR(7) NOT NULL,
  amount INT NOT NULL,
  paid_on DATE NOT NULL,
  method ENUM('tunai','transfer') NOT NULL,
  recorded_by VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_frp_company (company_id, period)
);

CREATE TABLE finance_klop (
  company_id VARCHAR(50) NOT NULL PRIMARY KEY,
  account VARCHAR(255) NULL,
  data VARCHAR(255) NULL,
  connected_at DATETIME NULL,
  last_sync_at DATETIME NULL
);
