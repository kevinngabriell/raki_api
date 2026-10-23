-- Menu revamp (dashboard docs/backend-endpoints-revamp.md §6). Additive only.

ALTER TABLE menu
  ADD COLUMN description TEXT NULL,
  ADD COLUMN sell_outlet TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN sell_abang TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN sugar_ice TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0;

-- Sugar & ice used to follow "everything except Topping / Add-on".
UPDATE menu m JOIN category_menu c ON c.category_id = m.category_id
   SET m.sugar_ice = 0 WHERE LOWER(c.category_name) REGEXP '^(topping|add-?on)$';

ALTER TABLE category_menu ADD COLUMN sort_order INT NOT NULL DEFAULT 1000000;
UPDATE category_menu c JOIN (
  SELECT category_id, ROW_NUMBER() OVER (ORDER BY created_at, category_id) AS rn FROM category_menu
) o ON o.category_id = c.category_id SET c.sort_order = o.rn;

ALTER TABLE package
  ADD COLUMN items JSON NULL,                     -- [{type:"fixed",menu_id,qty} | {type:"slot",name,pick,options:[menu_id],extras:{menu_id:rupiah}}]
  ADD COLUMN sell_outlet TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN sell_abang TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN available_from DATE NULL,
  ADD COLUMN available_to DATE NULL,
  ADD COLUMN available_days JSON NULL,            -- [0..6], 0 = Minggu; NULL = every day
  ADD COLUMN available_time_from CHAR(5) NULL,
  ADD COLUMN available_time_to CHAR(5) NULL;
