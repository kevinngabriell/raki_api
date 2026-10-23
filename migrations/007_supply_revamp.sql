-- Supply revamp (dashboard docs/backend-endpoints-revamp.md §10). Additive only.
ALTER TABLE supply_order
  ADD COLUMN processing_at DATETIME NULL,
  ADD COLUMN shipped_at DATETIME NULL,
  ADD COLUMN rejected_at DATETIME NULL,
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN reason VARCHAR(500) NULL;           -- why pusat rejected / cancelled; shown to the outlet

CREATE TABLE supply_payment (
  payment_id VARCHAR(50) NOT NULL PRIMARY KEY,
  supply_order_id VARCHAR(50) NOT NULL,
  amount INT NOT NULL,
  paid_on DATE NOT NULL,
  method ENUM('tunai','transfer') NOT NULL,
  proof_url VARCHAR(500) NULL,
  recorded_by VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sp_order (supply_order_id)
);

-- Hitung stok: a count becomes the new starting point of an outlet's stock.
CREATE TABLE supply_stock_count (
  count_id VARCHAR(50) NOT NULL PRIMARY KEY,
  company_id VARCHAR(50) NOT NULL,
  counted_at DATETIME NOT NULL,
  counted_by VARCHAR(50) NOT NULL,
  KEY idx_ssc_company (company_id, counted_at)
);
CREATE TABLE supply_stock_count_item (
  count_id VARCHAR(50) NOT NULL,
  ingredient_id VARCHAR(50) NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (count_id, ingredient_id)
);
