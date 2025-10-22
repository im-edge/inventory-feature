ALTER TABLE snmp_discovery_rule
  ADD COLUMN credential_uuid VARBINARY(16) NOT NULL AFTER uuid,
  ADD CONSTRAINT snmp_discovery_rule_credential
  FOREIGN KEY (credential_uuid)
  REFERENCES snmp_credential (credential_uuid);

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (12, 'inventory', NOW());
