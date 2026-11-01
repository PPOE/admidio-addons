# SPDX-License-Identifier: GPL-2.0-only
# Test only: sourced by the MariaDB entrypoint after schema.sql and
# users.sql. Applies every pgacc/*.sql (views and grants), as the deployment
# does.
for sql in /pgacc/*.sql; do
    docker_process_sql < "$sql"
done
