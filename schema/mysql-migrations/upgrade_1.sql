ALTER TABLE inventory_entity_ifmap ADD INDEX idx_device_if (device_uuid, if_index);

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (1, 'inventory', NOW());
