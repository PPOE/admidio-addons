-- SPDX-License-Identifier: GPL-2.0-only
--
-- The postcodes of the regions that have units in Liquid (liquid_members.sql):
-- AT130 (Wien, 23 postcodes) and AT221 (Graz, 62 postcodes), from the
-- postcode table of the old membership system. A postcode may belong to more
-- than one region; any match counts. Applied by the deployment, as an
-- administrator, in the Admidio database, before liquid_members.sql; applying
-- it again replaces the rows with exactly these. The character set and
-- collation are those of the Admidio tables, whatever the database's default
-- is, so that the join with adm_user_data works.

CREATE TABLE IF NOT EXISTS liquid_region_plz (
    plz CHAR(4) NOT NULL,
    nuts CHAR(5) NOT NULL,
    PRIMARY KEY (plz, nuts)
)
ENGINE = InnoDB
DEFAULT character SET = utf8
COLLATE = utf8_unicode_ci;

START TRANSACTION;
DELETE FROM liquid_region_plz;
INSERT INTO liquid_region_plz (nuts, plz) VALUES
    ('AT130', '1010'),
    ('AT130', '1020'),
    ('AT130', '1030'),
    ('AT130', '1040'),
    ('AT130', '1050'),
    ('AT130', '1060'),
    ('AT130', '1070'),
    ('AT130', '1080'),
    ('AT130', '1090'),
    ('AT130', '1100'),
    ('AT130', '1110'),
    ('AT130', '1120'),
    ('AT130', '1130'),
    ('AT130', '1140'),
    ('AT130', '1150'),
    ('AT130', '1160'),
    ('AT130', '1170'),
    ('AT130', '1180'),
    ('AT130', '1190'),
    ('AT130', '1200'),
    ('AT130', '1210'),
    ('AT130', '1220'),
    ('AT130', '1230'),
    ('AT221', '8010'),
    ('AT221', '8020'),
    ('AT221', '8036'),
    ('AT221', '8041'),
    ('AT221', '8042'),
    ('AT221', '8043'),
    ('AT221', '8044'),
    ('AT221', '8045'),
    ('AT221', '8046'),
    ('AT221', '8047'),
    ('AT221', '8051'),
    ('AT221', '8052'),
    ('AT221', '8054'),
    ('AT221', '8055'),
    ('AT221', '8061'),
    ('AT221', '8062'),
    ('AT221', '8063'),
    ('AT221', '8071'),
    ('AT221', '8072'),
    ('AT221', '8073'),
    ('AT221', '8074'),
    ('AT221', '8075'),
    ('AT221', '8077'),
    ('AT221', '8081'),
    ('AT221', '8101'),
    ('AT221', '8102'),
    ('AT221', '8103'),
    ('AT221', '8111'),
    ('AT221', '8112'),
    ('AT221', '8113'),
    ('AT221', '8114'),
    ('AT221', '8120'),
    ('AT221', '8121'),
    ('AT221', '8122'),
    ('AT221', '8124'),
    ('AT221', '8130'),
    ('AT221', '8131'),
    ('AT221', '8141'),
    ('AT221', '8142'),
    ('AT221', '8143'),
    ('AT221', '8144'),
    ('AT221', '8151'),
    ('AT221', '8152'),
    ('AT221', '8153'),
    ('AT221', '8160'),
    ('AT221', '8162'),
    ('AT221', '8163'),
    ('AT221', '8181'),
    ('AT221', '8200'),
    ('AT221', '8301'),
    ('AT221', '8302'),
    ('AT221', '8322'),
    ('AT221', '8323'),
    ('AT221', '8401'),
    ('AT221', '8402'),
    ('AT221', '8410'),
    ('AT221', '8411'),
    ('AT221', '8501'),
    ('AT221', '8503'),
    ('AT221', '8504'),
    ('AT221', '8561'),
    ('AT221', '9371');
COMMIT;
