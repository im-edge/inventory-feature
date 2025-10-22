ALTER TABLE snmp_interface_status
  MODIFY COLUMN stp_designated_port varbinary(8) NULL DEFAULT NULL;

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (11, 'inventory', NOW());
