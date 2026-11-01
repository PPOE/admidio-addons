# SPDX-License-Identifier: GPL-2.0-only
# Test only: sourced by the MariaDB entrypoint after schema.sql and
# users.sql. Applies every pgacc/*.sql and liquid/*.sql (tables, views and
# grants) in the deployment's order: liquid_members.sql reads the view
# pgacc_members and the table liquid_region_plz.
for sql in /pgacc/*.sql /liquid/liquid_region_plz.sql /liquid/liquid_members.sql; do
    docker_process_sql < "$sql"
done
