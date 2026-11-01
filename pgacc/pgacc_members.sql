-- SPDX-License-Identifier: GPL-2.0-only
--
-- The member list for pgacc (members-api.php): the view pgacc_members and
-- the grant for its reader. Applied by the deployment, as an administrator,
-- in the Admidio database (table prefix "adm"), after the reader user
-- 'pgacc_members'@'localhost' has been created with its password; applied
-- again after Admidio updates. The view runs with its definer's rights, so
-- the reader needs no access to the Admidio tables.
--
-- One row per user who is valid (usr_valid = 1) and has an active membership
-- of role 2 (mem_begin <= today <= mem_end):
--   id    usr_id
--   uuid  usr_uuid (Admidio 5 opens profiles by it)
--   name  "<first name> <last name>" (profile fields 2 and 1; a missing one
--         is empty)
--   lo    the active regional organisation role (37-45) with the latest
--         mem_begin, ties to the lowest role id (on the day an LO changes,
--         the old and the new one may both be active); NULL if none
--   paid  1 if "paid until" (profile field 26, an ISO date) is today or
--         later, else 0 (paid until 31 December counts as paid on that day)

CREATE OR REPLACE SQL SECURITY DEFINER VIEW pgacc_members AS
SELECT
    u.usr_id AS id,
    u.usr_uuid AS uuid,
    CONCAT(
        COALESCE((SELECT MAX(d.usd_value) FROM adm_user_data d WHERE d.usd_usr_id = u.usr_id AND d.usd_usf_id = 2), ''),
        ' ',
        COALESCE((SELECT MAX(d.usd_value) FROM adm_user_data d WHERE d.usd_usr_id = u.usr_id AND d.usd_usf_id = 1), '')
    ) AS name,
    (SELECT m.mem_rol_id FROM adm_members m
        WHERE m.mem_usr_id = u.usr_id AND m.mem_rol_id BETWEEN 37 AND 45
            AND m.mem_begin <= CURRENT_DATE AND m.mem_end >= CURRENT_DATE
        ORDER BY m.mem_begin DESC, m.mem_rol_id ASC LIMIT 1) AS lo,
    EXISTS (SELECT 1 FROM adm_user_data d
        WHERE d.usd_usr_id = u.usr_id AND d.usd_usf_id = 26
            AND d.usd_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' AND d.usd_value >= CURRENT_DATE) AS paid
FROM adm_users u
WHERE u.usr_valid = 1
    AND EXISTS (SELECT 1 FROM adm_members m
        WHERE m.mem_usr_id = u.usr_id AND m.mem_rol_id = 2
            AND m.mem_begin <= CURRENT_DATE AND m.mem_end >= CURRENT_DATE);

GRANT SELECT ON pgacc_members TO 'pgacc_members'@'localhost';
