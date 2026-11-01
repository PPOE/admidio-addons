-- SPDX-License-Identifier: GPL-2.0-only
-- Test only: the reader user that pgacc/pgacc_members.sql grants the view to
-- (the deployment creates it with its own password).
CREATE USER 'pgacc_members'@'localhost' IDENTIFIED BY 'test-reader-password';
