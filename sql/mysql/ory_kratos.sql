-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/OryKratos/sql/ory_kratos.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/ory_kratos (
  kratos_user INT UNSIGNED NOT NULL,
  kratos_id LONGBLOB NOT NULL,
  kratos_host LONGBLOB NOT NULL,
  INDEX ory_kratos_id (kratos_id, kratos_host),
  PRIMARY KEY(kratos_user)
) /*$wgDBTableOptions*/;
