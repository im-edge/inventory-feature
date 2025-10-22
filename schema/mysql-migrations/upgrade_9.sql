-- allowing NULL

ALTER TABLE inventory_physical_entity
  MODIFY COLUMN class ENUM(
    'other',        --  1
    'unknown',      --  2
    'chassis',      --  3
    'backplane',    --  4
    'container',    --  5
    'powerSupply',  --  6
    'fan',          --  7
    'sensor',       --  8
    'module',       --  9
    'port',         -- 10
    'stack',        -- 11
    'cpu',          -- 12
    'energyObject', -- 13
    'battery',      -- 14
    'storageDrive'  -- 15
  ) NULL DEFAULT 'unknown';

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (9, 'inventory', NOW());
