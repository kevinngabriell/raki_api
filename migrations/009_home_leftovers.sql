-- Home dashboard leftovers (dashboard docs/backend-endpoints-revamp.md §3). Additive only.
CREATE TABLE wa_recap_setting (
  company_id VARCHAR(50) NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  send_time CHAR(5) NOT NULL DEFAULT '21:00',
  destination VARCHAR(30) NULL,                   -- digits, 62...; NULL = the outlet's pic_contact
  last_sent_date DATE NULL,
  updated_by VARCHAR(50) NULL,
  updated_at DATETIME NULL
);
