-- Abang bonus payouts (dashboard docs/backend-endpoints-revamp.md §7). Additive only.
CREATE TABLE bonus_payout (
  company_id VARCHAR(50) NOT NULL,
  username VARCHAR(500) NOT NULL,
  week_start DATE NOT NULL,                       -- Monday of the Mon-Sat bonus week
  amount INT NOT NULL,
  paid_on DATE NOT NULL,
  paid_by VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, username(100), week_start),
  KEY idx_bp_week (company_id, week_start)
);
