-- Make redemption codes unique so a collision fails loudly instead of issuing duplicate claim codes.
-- Run against the RAKI schema (the one DB_SCHEMA points to), not movira_core.
--
-- Pre-check: this must return no rows, otherwise the ALTER fails.
--   SELECT redemption_code, COUNT(*) c FROM reward_redemption GROUP BY redemption_code HAVING c > 1;

ALTER TABLE `reward_redemption`
  ADD UNIQUE KEY `uq_reward_redemption_code` (`redemption_code`);
