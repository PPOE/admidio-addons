-- SPDX-License-Identifier: GPL-2.0-only
--
-- Test schema: the three Admidio 5.0.17 tables that the view pgacc_members
-- reads, copied unchanged (prefix "adm") from install/db_scripts/db.sql of
-- the Admidio 5.0.17 release (copyright The Admidio Team, GPL-2.0-only).
-- Foreign keys are left out, so the tests need no organisations, categories,
-- roles or profile fields. No data.

CREATE TABLE adm_members
(
    mem_id                      integer unsigned    NOT NULL    AUTO_INCREMENT,
    mem_rol_id                  integer unsigned    NOT NULL,
    mem_usr_id                  integer unsigned    NOT NULL,
    mem_uuid                    varchar(36)         NOT NULL,
    mem_begin                   date                NOT NULL,
    mem_end                     date                NOT NULL    DEFAULT '9999-12-31',
    mem_leader                  boolean             NOT NULL    DEFAULT false,
    mem_usr_id_create           integer unsigned,
    mem_timestamp_create        timestamp           NOT NULL    DEFAULT CURRENT_TIMESTAMP,
    mem_usr_id_change           integer unsigned,
    mem_timestamp_change        timestamp           NULL        DEFAULT NULL,
    mem_approved                integer unsigned    NULL        DEFAULT NULL,
    mem_comment                 varchar(4000),
    mem_count_guests            integer unsigned    NOT NULL    DEFAULT 0,
    PRIMARY KEY (mem_id)
)
ENGINE = InnoDB
DEFAULT character SET = utf8
COLLATE = utf8_unicode_ci;
CREATE INDEX adm_idx_mem_rol_usr_id ON adm_members (mem_rol_id, mem_usr_id);
CREATE UNIQUE INDEX adm_idx_mem_uuid ON adm_members (mem_uuid);

CREATE TABLE adm_user_data
(
    usd_id                      integer unsigned    NOT NULL    AUTO_INCREMENT,
    usd_usr_id                  integer unsigned    NOT NULL,
    usd_usf_id                  integer unsigned    NOT NULL,
    usd_value                   varchar(4000),
    PRIMARY KEY (usd_id)
)
ENGINE = InnoDB
DEFAULT character SET = utf8
COLLATE = utf8_unicode_ci;
CREATE UNIQUE INDEX adm_idx_usd_usr_usf_id ON adm_user_data (usd_usr_id, usd_usf_id);

CREATE TABLE adm_users
(
    usr_id                      integer unsigned    NOT NULL    AUTO_INCREMENT,
    usr_uuid                    varchar(36)         NOT NULL,
    usr_login_name              varchar(254),
    usr_password                varchar(255),
    usr_tfa_secret              varchar(255),
    usr_photo                   blob,
    usr_text                    text,
    usr_pw_reset_id             varchar(50),
    usr_pw_reset_timestamp      timestamp           NULL        DEFAULT NULL,
    usr_last_login              timestamp           NULL        DEFAULT NULL,
    usr_actual_login            timestamp           NULL        DEFAULT NULL,
    usr_number_login            integer             NOT NULL    DEFAULT 0,
    usr_date_invalid            timestamp           NULL        DEFAULT NULL,
    usr_number_invalid          smallint            NOT NULL    DEFAULT 0,
    usr_usr_id_create           integer unsigned,
    usr_timestamp_create        timestamp           NOT NULL    DEFAULT CURRENT_TIMESTAMP,
    usr_usr_id_change           integer unsigned,
    usr_timestamp_change        timestamp           NULL        DEFAULT NULL,
    usr_valid                   boolean             NOT NULL    DEFAULT false,
    PRIMARY KEY (usr_id)
)
ENGINE = InnoDB
DEFAULT character SET = utf8
COLLATE = utf8_unicode_ci;
CREATE UNIQUE INDEX adm_idx_usr_login_name ON adm_users (usr_login_name);
CREATE UNIQUE INDEX adm_idx_usr_uuid ON adm_users (usr_uuid);
