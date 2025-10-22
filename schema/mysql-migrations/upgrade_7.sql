ALTER TABLE datanode
  ADD COLUMN db_stream_position VARCHAR(21) NULL DEFAULT NULL AFTER label,
  ADD COLUMN db_stream_error TEXT DEFAULT NULL AFTER db_stream_position;
UPDATE datanode SET db_stream_position = '0-0';
ALTER TABLE datanode
  MODIFY COLUMN db_stream_position VARCHAR(21) NOT NULL;

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (7, 'inventory', NOW());
