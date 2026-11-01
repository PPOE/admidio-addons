-- SPDX-License-Identifier: GPL-2.0-only
--
-- Test schema: the five Admidio 5.0.17 tables that accsync.php reads or
-- writes, copied unchanged (prefix "adm") from install/db_scripts/db.sql of
-- the Admidio 5.0.17 release (copyright The Admidio Team, GPL-2.0-only).
-- Foreign keys are left out, so the tests need no organisations or
-- categories. No data.

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

CREATE TABLE adm_roles
(
    rol_id                      integer unsigned    NOT NULL    AUTO_INCREMENT,
    rol_cat_id                  integer unsigned    NOT NULL,
    rol_lst_id                  integer unsigned,
    rol_uuid                    varchar(36)         NOT NULL,
    rol_name                    varchar(100)        NOT NULL,
    rol_description             varchar(4000),
    rol_assign_roles            boolean             NOT NULL    DEFAULT false,
    rol_approve_users           boolean             NOT NULL    DEFAULT false,
    rol_announcements           boolean             NOT NULL    DEFAULT false,
    rol_events                  boolean             NOT NULL    DEFAULT false,
    rol_documents_files         boolean             NOT NULL    DEFAULT false,
    rol_inventory_admin         boolean             NOT NULL    DEFAULT false,
    rol_edit_user               boolean             NOT NULL    DEFAULT false,
    rol_forum_admin             boolean             NOT NULL    DEFAULT false,
    rol_mail_to_all             boolean             NOT NULL    DEFAULT false,
    rol_mail_this_role          smallint            NOT NULL    DEFAULT 0,
    rol_photo                   boolean             NOT NULL    DEFAULT false,
    rol_profile                 boolean             NOT NULL    DEFAULT false,
    rol_weblinks                boolean             NOT NULL    DEFAULT false,
    rol_all_lists_view          boolean             NOT NULL    DEFAULT false,
    rol_default_registration    boolean             NOT NULL    DEFAULT false,
    rol_leader_rights           smallint            NOT NULL    DEFAULT 0,
    rol_view_memberships        smallint            NOT NULL    DEFAULT 0,
    rol_view_members_profiles   smallint            NOT NULL    DEFAULT 0,
    rol_start_date              date,
    rol_start_time              time,
    rol_end_date                date,
    rol_end_time                time,
    rol_weekday                 smallint,
    rol_location                varchar(100),
    rol_max_members             integer,
    rol_cost                    float,
    rol_cost_period             smallint,
    rol_usr_id_create           integer unsigned,
    rol_timestamp_create        timestamp           NOT NULL    DEFAULT CURRENT_TIMESTAMP,
    rol_usr_id_change           integer unsigned,
    rol_timestamp_change        timestamp           NULL        DEFAULT NULL,
    rol_valid                   boolean             NOT NULL    DEFAULT true,
    rol_system                  boolean             NOT NULL    DEFAULT false,
    rol_administrator           boolean             NOT NULL    DEFAULT false,
    PRIMARY KEY (rol_id)
)
ENGINE = InnoDB
DEFAULT character SET = utf8
COLLATE = utf8_unicode_ci;

CREATE UNIQUE INDEX adm_idx_rol_uuid ON adm_roles (rol_uuid);

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

CREATE TABLE adm_user_fields
(
    usf_id                      integer unsigned    NOT NULL    AUTO_INCREMENT,
    usf_cat_id                  integer unsigned    NOT NULL,
    usf_uuid                    varchar(36)         NOT NULL,
    usf_type                    varchar(30)         NOT NULL,
    usf_name_intern             varchar(110)        NOT NULL,
    usf_name                    varchar(100)        NOT NULL,
    usf_description             text,
    usf_default_value           varchar(100),
    usf_regex                   varchar(100),
    usf_icon                    varchar(100),
    usf_url                     varchar(2000),
    usf_system                  boolean             NOT NULL    DEFAULT false,
    usf_disabled                boolean             NOT NULL    DEFAULT false,
    usf_hidden                  boolean             NOT NULL    DEFAULT false,
    usf_registration            boolean             NOT NULL    DEFAULT false,
    usf_required_input          smallint            NOT NULL    DEFAULT 0,
    usf_sequence                smallint            NOT NULL,
    usf_usr_id_create           integer unsigned,
    usf_timestamp_create        timestamp           NOT NULL    DEFAULT CURRENT_TIMESTAMP,
    usf_usr_id_change           integer unsigned,
    usf_timestamp_change        timestamp           NULL        DEFAULT NULL,
    PRIMARY KEY (usf_id)
)
ENGINE = InnoDB
DEFAULT character SET = utf8
COLLATE = utf8_unicode_ci;

CREATE UNIQUE INDEX adm_idx_usf_name_intern ON adm_user_fields (usf_name_intern);
CREATE UNIQUE INDEX adm_idx_usf_uuid ON adm_user_fields (usf_uuid);

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
