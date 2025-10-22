-- TODO: Do we really need the node UUID here? -> PollInterfaceConfig
ALTER TABLE snmp_interface_config
    ADD COLUMN datanode_uuid VARBINARY(16) NOT NULL AFTER system_uuid;

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (4, 'inventory', NOW());
