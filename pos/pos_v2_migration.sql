-- Schema changes for Kasir Outlet POS v2 + self-registration approval
-- (docs: api-requirements-auth-pos.md §1–§5). Run manually.
--
-- DEPLOY ORDER: apply this BEFORE deploying the code. Every change is a new
-- nullable column, so the current code keeps working with it, but the new code
-- writes these columns on every POS sale and fails without them.
--
-- Part 1 runs once per RAKI schema (`raki_dev` first, then `raki`):
--
--   mysql -h <host> -u <user> -p raki_dev < pos/pos_v2_migration.sql
--
-- Part 2 is a read-only check on movira_core_dev (shared with other apps).


-- ───────────── Part 1: RAKI schema ─────────────

-- §4 No. Antrian of POS sales (NULL for dashboard entries and older sales).
ALTER TABLE `transaction`
  ADD COLUMN `queue_number` int DEFAULT NULL;

-- §2 Lets /pos/history.php fold a package's component rows back into one line:
-- package_id of the package the row came from, line_no = index in the cart.
ALTER TABLE `transaction_detail`
  ADD COLUMN `package_id` varchar(50) DEFAULT NULL,
  ADD COLUMN `line_no` int DEFAULT NULL;

-- §3 EDC/Flazz receipt number ("No. Referensi"), only set for edc_flazz.
ALTER TABLE `transaction_payment`
  ADD COLUMN `reference_no` varchar(50) DEFAULT NULL;

-- §5 Package photo, same meaning as menu.image_url / menu.thumb_url.
ALTER TABLE `package`
  ADD COLUMN `image_url` varchar(255) DEFAULT NULL,
  ADD COLUMN `thumb_url` varchar(255) DEFAULT NULL;


-- ───────────── Part 2: movira_core_dev.app_user (check only) ─────────────
--
-- account/register.php now inserts sign-ups with
--   account_status = 'pending', app_role_id = NULL, company_id = NULL
-- and account/pending.php sets account_status to 'active' or 'rejected'.
-- Existing users need no backfill: login only refuses 'pending' and 'rejected',
-- so NULL and any older value keep working.
--
-- Check before deploying:
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'movira_core_dev' AND TABLE_NAME = 'app_user'
  AND COLUMN_NAME IN ('account_status', 'app_role_id', 'company_id', 'first_name', 'phone_number', 'email');
--
-- What the result must show:
--   account_status  a varchar/char (or an ENUM that includes 'pending', 'active' and 'rejected')
--   app_role_id     IS_NULLABLE = YES
--   company_id      IS_NULLABLE = YES
--   first_name / email: at least 100 characters (register allows up to 100)
-- If app_role_id is NOT NULL, registration fails with a 500 until it is made
-- nullable (a MODIFY that restates the column's existing type/collation exactly).
