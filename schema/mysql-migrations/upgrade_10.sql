-- TODO: really?
ALTER TABLE rrd_file
  ADD COLUMN tags TEXT NULL DEFAULT NULL AFTER instance;

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (10, 'inventory', NOW());
