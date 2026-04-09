ALTER TABLE data_autonomous_system
  ADD COLUMN country_code CHAR(2) NOT NULL AFTER description;

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
  VALUES (13, 'inventory', NOW());
