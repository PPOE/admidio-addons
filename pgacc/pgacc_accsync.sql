-- SPDX-License-Identifier: GPL-2.0-only
--
-- The grants of the writer of accsync.php, 'pgacc_accsync'@'localhost'.
-- Applied by the deployment, as an administrator, in the Admidio database
-- (table prefix "adm"), after the user has been created with its password;
-- applied again after Admidio updates. Column privileges only: the
-- deployment check (profile fields and roles), eligibility (users and role
-- memberships) and the profile values of the members (user data: read,
-- insert, update; accsync.php writes fields 26 and 27 only). MariaDB checks
-- the UPDATE privilege of INSERT ... ON DUPLICATE KEY UPDATE on every
-- inserted column, so UPDATE covers the three columns, not usd_value alone.

GRANT SELECT (usf_id, usf_type, usf_name_intern) ON adm_user_fields TO 'pgacc_accsync'@'localhost';
GRANT SELECT (rol_id) ON adm_roles TO 'pgacc_accsync'@'localhost';
GRANT SELECT (usr_id, usr_valid) ON adm_users TO 'pgacc_accsync'@'localhost';
GRANT SELECT (mem_usr_id, mem_rol_id, mem_begin, mem_end) ON adm_members TO 'pgacc_accsync'@'localhost';
GRANT SELECT (usd_usr_id, usd_usf_id, usd_value), INSERT (usd_usr_id, usd_usf_id, usd_value), UPDATE (usd_usr_id, usd_usf_id, usd_value)
    ON adm_user_data TO 'pgacc_accsync'@'localhost';
