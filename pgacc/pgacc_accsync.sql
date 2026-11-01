-- SPDX-License-Identifier: GPL-2.0-only
--
-- The view pgacc_accsync_user_data and the grants of the writer of
-- accsync.php, 'pgacc_accsync'@'localhost'. Applied by the deployment, as an
-- administrator, in the Admidio database (table prefix "adm"), after the
-- user has been created with its password; applied again after Admidio
-- updates. It first revokes everything, so the grants below are all the
-- writer has. Column privileges only: the deployment check (profile fields
-- and roles; REFERENCES on the two key columns of adm_user_data only makes
-- its unique key visible in information_schema, it gives no access to the
-- data) and eligibility (users and role memberships). The profile values
-- are read and written through the view, which runs with its definer's
-- rights and holds only fields 26 and 27 ("paid until" and amount); WITH
-- CHECK OPTION refuses any insert or update that would leave them, also
-- through INSERT ... ON DUPLICATE KEY UPDATE.

CREATE OR REPLACE SQL SECURITY DEFINER VIEW pgacc_accsync_user_data AS
SELECT usd_usr_id, usd_usf_id, usd_value
FROM adm_user_data
WHERE usd_usf_id IN (26, 27)
WITH CHECK OPTION;

REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'pgacc_accsync'@'localhost';
GRANT SELECT (usf_id, usf_type, usf_name_intern) ON adm_user_fields TO 'pgacc_accsync'@'localhost';
GRANT SELECT (rol_id) ON adm_roles TO 'pgacc_accsync'@'localhost';
GRANT SELECT (usr_id, usr_valid) ON adm_users TO 'pgacc_accsync'@'localhost';
GRANT SELECT (mem_usr_id, mem_rol_id, mem_begin, mem_end) ON adm_members TO 'pgacc_accsync'@'localhost';
GRANT REFERENCES (usd_usr_id, usd_usf_id) ON adm_user_data TO 'pgacc_accsync'@'localhost';
GRANT SELECT, INSERT, UPDATE ON pgacc_accsync_user_data TO 'pgacc_accsync'@'localhost';
