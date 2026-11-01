-- SPDX-License-Identifier: GPL-2.0-only
-- Test only: the database users that the add-ons' SQL files (pgacc/*.sql,
-- liquid/*.sql) grant to. The deployment creates them with their own passwords.
CREATE USER 'pgacc_members'@'localhost' IDENTIFIED BY 'test-reader-password';
CREATE USER 'pgacc_accsync'@'localhost' IDENTIFIED BY 'test-writer-password';
CREATE USER 'liquid_members'@'localhost' IDENTIFIED BY 'test-liquid-reader-password';
