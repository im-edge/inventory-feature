ALTER TABLE rrd_file ADD INDEX search_ci (device_uuid, measurement_name, instance(128));

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (2, 'inventory', NOW());
