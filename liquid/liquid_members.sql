-- SPDX-License-Identifier: GPL-2.0-only
--
-- The member list for Liquid (members-api.php): the view liquid_members and
-- the grant for its reader. Applied by the deployment, as an administrator,
-- in the Admidio database (table prefix "adm"), after pgacc_members.sql and
-- liquid_region_plz.sql and after the reader user
-- 'liquid_members'@'localhost' has been created with its password; applied
-- again after Admidio updates. The view runs with its definer's rights, so
-- the reader needs no access to the tables or to pgacc_members.
--
-- One row per member of the view pgacc_members (the same members and LO):
--   id        usr_id
--   lo        the LO of pgacc_members; NULL if none
--   eligible  1 if accredited (profile field 35) on or before today, and
--             paid (field 26, "paid until") or accredited at most 30 days
--             ago (the trial month), as adm1.php of the old membership
--             system: "paid until 31 December" is eligible through 30
--             January. Both fields count only as dates in the form
--             YYYY-MM-DD that STR_TO_DATE accepts (so not 30 February,
--             unless the SQL mode has ALLOW_INVALID_DATES); field 35 also
--             without a zero year, month or day, which the default SQL
--             mode lets through (for field 26, adding 30 days to such a
--             value gives NULL). Else 0
--   email     only when eligible: profile field 12, or field 33 when field
--             12 is mitglied@piratenpartei.at; else NULL
--   regions   the distinct codes (sorted, comma-separated) of the postcodes
--             in fields 4 and 39 (trimmed, exact match) in
--             liquid_region_plz; NULL if none

CREATE OR REPLACE SQL SECURITY DEFINER VIEW liquid_members AS
SELECT
    m.id,
    m.lo,
    m.eligible,
    IF(m.eligible, IF(m.email = 'mitglied@piratenpartei.at', m.backup_email, m.email), NULL) AS email,
    m.regions
FROM (
    SELECT
        a.id,
        a.lo,
        COALESCE(a.accredited <= CURRENT_DATE
            AND (a.paid_until + INTERVAL 30 DAY >= CURRENT_DATE OR a.accredited + INTERVAL 30 DAY >= CURRENT_DATE), 0) AS eligible,
        a.email,
        a.backup_email,
        a.regions
    FROM (
        SELECT
            p.id,
            p.lo,
            (SELECT MAX(STR_TO_DATE(d.usd_value, '%Y-%m-%d')) FROM adm_user_data d
                WHERE d.usd_usr_id = p.id AND d.usd_usf_id = 35
                    AND DATE_FORMAT(STR_TO_DATE(d.usd_value, '%Y-%m-%d'), '%Y-%m-%d') = d.usd_value
                    AND YEAR(STR_TO_DATE(d.usd_value, '%Y-%m-%d')) > 0 AND MONTH(STR_TO_DATE(d.usd_value, '%Y-%m-%d')) > 0
                    AND DAYOFMONTH(STR_TO_DATE(d.usd_value, '%Y-%m-%d')) > 0) AS accredited,
            (SELECT MAX(STR_TO_DATE(d.usd_value, '%Y-%m-%d')) FROM adm_user_data d
                WHERE d.usd_usr_id = p.id AND d.usd_usf_id = 26
                    AND DATE_FORMAT(STR_TO_DATE(d.usd_value, '%Y-%m-%d'), '%Y-%m-%d') = d.usd_value) AS paid_until,
            (SELECT MAX(d.usd_value) FROM adm_user_data d WHERE d.usd_usr_id = p.id AND d.usd_usf_id = 12) AS email,
            (SELECT MAX(d.usd_value) FROM adm_user_data d WHERE d.usd_usr_id = p.id AND d.usd_usf_id = 33) AS backup_email,
            (SELECT GROUP_CONCAT(DISTINCT r.nuts ORDER BY r.nuts) FROM adm_user_data d
                JOIN liquid_region_plz r ON r.plz = TRIM(d.usd_value)
                WHERE d.usd_usr_id = p.id AND d.usd_usf_id IN (4, 39)) AS regions
        FROM pgacc_members p
    ) a
) m;

GRANT SELECT ON liquid_members TO 'liquid_members'@'localhost';
