ALTER TABLE snmp_agent ADD COLUMN environment_uuid VARBINARY(16) NULL DEFAULT NULL AFTER lifecycle_uuid;
UPDATE snmp_agent SET environment_uuid = 0xb8ac93707916500d8d5f49a769f51ad4;
ALTER TABLE snmp_agent MODIFY COLUMN environment_uuid VARBINARY(16) NOT NULL;
ALTER TABLE snmp_agent ADD
  CONSTRAINT snmp_agent_environment
    FOREIGN KEY (environment_uuid)
    REFERENCES system_environment (uuid);

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (6, 'inventory', NOW());
