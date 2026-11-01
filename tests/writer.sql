-- SPDX-License-Identifier: GPL-2.0-only
-- Test only: the writer user that pgacc/pgacc_accsync.sql grants to (the
-- deployment creates it with its own password).
CREATE USER 'pgacc_accsync'@'localhost' IDENTIFIED BY 'test-writer-password';
