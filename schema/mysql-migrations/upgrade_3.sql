ALTER TABLE snmp_credential
  MODIFY COLUMN priv_protocol ENUM('des', 'des3', 'aes128', 'aes192', 'aes192c', 'aes256', 'aes256c') DEFAULT NULL;

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (3, 'inventory', NOW());
