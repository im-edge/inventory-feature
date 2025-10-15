CREATE TABLE schema_migration (
  schema_version SMALLINT UNSIGNED NOT NULL,
  component_name VARCHAR(64) NOT NULL,
  migration_time DATETIME NOT NULL,
  PRIMARY KEY(schema_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE daemon_info (
  instance_uuid VARBINARY(16) NOT NULL, -- random by daemon
  schema_version SMALLINT UNSIGNED NOT NULL,
  fqdn VARCHAR(255) NOT NULL,
  username VARCHAR(64) NOT NULL,
  pid INT UNSIGNED NOT NULL,
  binary_path VARCHAR(128) NOT NULL,
  binary_realpath VARCHAR(128) NOT NULL,
  php_binary_path VARCHAR(128) NOT NULL,
  php_binary_realpath VARCHAR(128) NOT NULL,
  php_version VARCHAR(64) NOT NULL,
  php_integer_size SMALLINT NOT NULL,
  running_with_systemd ENUM('y', 'n') NOT NULL,
  ts_started BIGINT(20) NOT NULL,
  ts_stopped BIGINT(20) DEFAULT NULL,
  ts_last_modification BIGINT(20) DEFAULT NULL,
  ts_last_update BIGINT(20) DEFAULT NULL,
  process_info MEDIUMTEXT NOT NULL,
  PRIMARY KEY (instance_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (1, 'inventory', NOW());
