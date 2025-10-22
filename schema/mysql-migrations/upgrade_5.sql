ALTER TABLE snmp_agent ADD COLUMN lifecycle_uuid VARBINARY(16) NULL DEFAULT NULL AFTER datanode_uuid;
UPDATE snmp_agent SET lifecycle_uuid = 0x7d214595d0965032b7de7615e4464b40;
ALTER TABLE snmp_agent MODIFY COLUMN lifecycle_uuid VARBINARY(16) NOT NULL;
ALTER TABLE snmp_agent ADD
  CONSTRAINT snmp_agent_lifecycle
    FOREIGN KEY (lifecycle_uuid)
    REFERENCES system_lifecycle (uuid);

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (5, 'inventory', NOW());
