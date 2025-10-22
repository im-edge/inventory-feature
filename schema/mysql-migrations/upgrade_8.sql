-- if_type used to be NOT NULL, but can be missing
ALTER TABLE snmp_interface_config
    MODIFY COLUMN if_type MEDIUMINT(8) UNSIGNED NULL DEFAULT NULL;

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (8, 'inventory', NOW());
