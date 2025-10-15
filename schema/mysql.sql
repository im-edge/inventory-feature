-- CREATE DATABASE inventory  DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
-- CREATE USER inventory@localhost IDENTIFIED BY '***';
-- GRANT ALL ON inventory.* TO inventory@localhost;
-- SET SQL_QUOTE_SHOW_CREATE = 0;

CREATE TABLE data_mac_address_block (
  prefix varbinary(8) NOT NULL, -- 3 bytes for MA-L, 3.5 MA-M, 4.5 MA-S
  prefix_length TINYINT NOT NULL, -- 24 (MA-L, OUI), 28 (MA-M), 36 (MA-S), 36 (IAB)
  company varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  address text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (prefix),
  INDEX idx_search (company)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE data_known_zipcode (
  uuid VARBINARY(16) NOT NULL,
  country_code CHAR (2) NOT NULL,
  zip VARCHAR(6) NOT NULL,
  place VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  state VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  state_code VARCHAR(2) NOT NULL,
  location POINT NULL DEFAULT NULL,
  PRIMARY KEY (uuid),
  INDEX idx_country_zip (country_code, zip),
  INDEX idx_country_place (country_code, place),
  INDEX idx_search_place (place, country_code, state, zip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;



CREATE TABLE datanode (
  uuid VARBINARY(16) NOT NULL,
  label VARCHAR(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  -- config TEXT NULL DEFAULT NULL,
  -- TODO: node_health, last_seen?
  -- TODO: node_type: node, sub-process
  -- TODO:
  PRIMARY KEY (uuid),
  UNIQUE INDEX (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE datanode_table_sync (
  datanode_uuid VARBINARY(16) NOT NULL,
  table_name VARCHAR(32) NOT NULL,
  current_position VARCHAR(21) NOT NULL,
  current_error TEXT DEFAULT NULL,
  PRIMARY KEY (datanode_uuid, table_name),
  CONSTRAINT table_sync_datanode
    FOREIGN KEY table_sync_datanode_uuid (datanode_uuid)
      REFERENCES datanode (uuid)
      ON DELETE CASCADE
      ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE datanode_table_action_history (
  datanode_uuid VARBINARY(16) NOT NULL,
  table_name VARCHAR(32) NOT NULL,
  stream_position VARCHAR(21) NOT NULL,
  action ENUM('create', 'update', 'delete') NOT NULL,
  key_properties VARCHAR(255) NOT NULL,
  sent_values TEXT DEFAULT NULL,
  PRIMARY KEY (datanode_uuid, table_name, stream_position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;



CREATE TABLE inventory_raw_svg (
  svg_checksum VARBINARY(20) NOT NULL,
  raw_xml TEXT NOT NULL,
  PRIMARY KEY (svg_checksum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;



CREATE TABLE inventory_address (
  uuid VARBINARY(16) NOT NULL,
  -- checksum VARCHAR(20) DEFAULT NULL, -- not yet
  street VARCHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  zip VARCHAR(6) DEFAULT NULL,
  city_name VARCHAR(64) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  country_code CHAR(2) NOT NULL,
  -- removed, might be part of customer_address or similar
  -- address_type ENUM(
    --   'private',
    --   'office',
    --   'others',
    --   'user_defined'
    -- ) COLLATE utf8mb4_unicode_ci,
  -- TODO: altitude?
  -- city_id INT(10) NULL DEFAULT NULL,
  location POINT NULL DEFAULT NULL, -- longitude/latitude
  nominatim_lookup_key VARCHAR(255) DEFAULT NULL,
  bounding_box POLYGON NULL DEFAULT NULL,
  PRIMARY KEY (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE inventory_site (
  uuid VARBINARY(16) NOT NULL,
  site_name VARCHAR(128) NOT NULL COLLATE utf8mb4_unicode_ci,
  site_type ENUM(
    'other',
    'headOffice',
    'branchOffice',
    'businessUnit',
    'plant',
    'datacenter',
    'ship',
    'cloudProvider'
  ) NOT NULL,
  address_uuid VARBINARY(16) DEFAULT NULL,
  PRIMARY KEY (uuid),
  UNIQUE INDEX (site_name),
  CONSTRAINT inventory_site_address
    FOREIGN KEY address (address_uuid)
      REFERENCES inventory_address (uuid)
      ON DELETE RESTRICT
      ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE inventory_facility (
  facility_uuid VARBINARY(16) NOT NULL,
  parent_facility_uuid VARBINARY(16) NULL DEFAULT NULL,
  facility_name VARCHAR(128) NOT NULL,
  description TEXT DEFAULT NULL,
  facility_type ENUM (
    'other',
    'building',
    'campus',
    'floor',
    'room',
    'rack',
    'table',
    'elevator',
    'stair',
    'enclosure'
  ) NOT NULL,
  other_type_name VARCHAR(64) DEFAULT NULL,
  width_mm INT(10) UNSIGNED DEFAULT NULL, -- x
  height_mm INT(10) UNSIGNED DEFAULT NULL, -- y
  depth_mm INT(10) UNSIGNED DEFAULT NULL, -- z
  origin_x ENUM(
    'left',
    'right'
  ) DEFAULT NULL,
  origin_y ENUM(
    'bottom',
    'top'
  ) DEFAULT NULL,
  origin_z ENUM(
    'front',
    'back'
  ) DEFAULT NULL,
  orientation FLOAT DEFAULT NULL, -- compass?
  map_svg_checksum VARBINARY(20) DEFAULT NULL,
  PRIMARY KEY (facility_uuid),
  CONSTRAINT parent_facility
    FOREIGN KEY parent_facility_uuid (parent_facility_uuid)
    REFERENCES inventory_facility (facility_uuid)
    ON DELETE RESTRICT
    ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;








CREATE TABLE inventory_vendor (
  vendor_uuid VARBINARY(16) NOT NULL,
  vendor_name VARCHAR(64) NOT NULL,
  description TEXT DEFAULT NULL,
  address_uuid VARBINARY(16) DEFAULT NULL,
  PRIMARY KEY (vendor_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

-- TODO: UUIDv5 with dedicated namespace for defaults
-- INSERT INTO inventory_vendor SET
--   vendor_uuid = UNHEX(REPLACE('00000000-0000-0000-0000-000000000000', '-', '')),
--   vendor_name = 'unknown';




CREATE TABLE inventory_rack_model (
  rack_model_uuid VARBINARY(20) NOT NULL,
  model_name VARCHAR(64) NOT NULL,
  vendor_uuid VARBINARY(16) NOT NULL,
  serial_number VARCHAR(64) DEFAULT NULL,
  available_units SMALLINT NOT NULL,
  width_inch FLOAT NOT NULL,
  height_inch FLOAT NOT NULL,
  depth_inch FLOAT NOT NULL,
  -- TODO: weight =>
  svg_implementation VARCHAR(128) NOT NULL,
  PRIMARY KEY (rack_model_uuid),
  CONSTRAINT rack_model_vendor
    FOREIGN KEY rack_model_vendor_uuid (vendor_uuid)
    REFERENCES inventory_vendor (vendor_uuid)
    ON DELETE RESTRICT
    ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

-- INSERT INTO inventory_rack_model SET
--   rack_model_uuid = UNHEX(REPLACE(UUID(), '-', '')),
--   vendor_uuid = (SELECT vendor_uuid FROM inventory_vendor WHERE vendor_name = 'unknown'),
--   model_name = 'Generic 42 units rack',
--   available_units = 42,
--
--   -- Werte sind sinnfrei:
--   width_inch = 21,
--   height_inch = 28,
--   depth_inch = 77,
--   svg_implementation = 'director/RackSvg';
-- -- 200x80
-- -- 1" = 2,54cm


-- naming -> is "device" correct?

CREATE TABLE inventory_device_model (
  device_model_uuid VARBINARY(16) NOT NULL,
  device_name VARCHAR(64) NOT NULL,
  vendor_uuid VARBINARY(16) NOT NULL, -- TODO: remove, it's on the device
  width_mm INT(10) UNSIGNED DEFAULT NULL, -- x
  height_mm INT(10) UNSIGNED DEFAULT NULL, -- y
  depth_mm INT(10) UNSIGNED DEFAULT NULL, -- z
  -- TODO: weight
  svg_implementation VARCHAR(128) NOT NULL,
  -- TODO: Svg Params
  PRIMARY KEY (device_model_uuid),
  CONSTRAINT device_model_vendor
    FOREIGN KEY device_model_vendor_uuid (vendor_uuid)
    REFERENCES inventory_vendor (vendor_uuid)
    ON DELETE RESTRICT
    ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

-- TODO: implement rack/device_model-capabilities



-- unused, currently using reduced snmp_agent table
CREATE TABLE inventory_device (
  device_uuid VARBINARY(16) NOT NULL,
  device_model_uuid VARBINARY(16) NOT NULL,
  device_name VARCHAR(64) NOT NULL,
  serial_number VARCHAR(64) DEFAULT NULL,
  -- TODO: kill these? We could enforce a model
  width_mm INT(10) UNSIGNED DEFAULT NULL, -- x
  height_mm INT(10) UNSIGNED DEFAULT NULL, -- y
  depth_mm INT(10) UNSIGNED DEFAULT NULL, -- z

  PRIMARY KEY (device_uuid),
  INDEX idx_name (device_name),
  INDEX idx_serial (serial_number),
  CONSTRAINT device_model
    FOREIGN KEY device_model_vendor_uuid (device_model_uuid)
    REFERENCES inventory_device_model (device_model_uuid)
    ON DELETE RESTRICT
    ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE inventory_rack (
  rack_uuid VARBINARY(16) NOT NULL,
  rack_model_uuid VARBINARY(16) NOT NULL,
  rack_name VARCHAR(64) NOT NULL,
  serial_number VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (rack_uuid),
  INDEX idx_name (rack_name),
  INDEX idx_serial (serial_number),
  CONSTRAINT rack_rack_model
    FOREIGN KEY rack_model_uuid (rack_model_uuid)
    REFERENCES inventory_rack_model (rack_model_uuid)
    ON DELETE RESTRICT
    ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE inventory_facility_rack_map (
  facility_uuid VARBINARY(16) NOT NULL,
  rack_uuid VARBINARY(16) NOT NULL,
  units SMALLINT NOT NULL,
  offset_x_mm INT(10) UNSIGNED DEFAULT NULL, -- width
  offset_y_mm INT(10) UNSIGNED DEFAULT NULL, -- height
  offset_z_mm INT(10) UNSIGNED DEFAULT NULL, -- depth
  PRIMARY KEY (facility_uuid, rack_uuid),
  INDEX idx_reverse (rack_uuid, facility_uuid),
  CONSTRAINT facility_rack_map_facility
    FOREIGN KEY facility_uuid (facility_uuid)
    REFERENCES inventory_facility (facility_uuid)
    ON DELETE RESTRICT
    ON UPDATE RESTRICT,
  CONSTRAINT facility_rack_map_rack
    FOREIGN KEY rack_uuid (rack_uuid)
    REFERENCES inventory_rack (rack_uuid)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE inventory_rack_device_map (
  rack_uuid VARBINARY(16) NOT NULL,
  device_uuid VARBINARY(16) NOT NULL,
  units SMALLINT NOT NULL,
  offset_x_mm INT(10) UNSIGNED DEFAULT NULL, -- width
  offset_y_mm INT(10) UNSIGNED DEFAULT NULL, -- height
  offset_z_mm INT(10) UNSIGNED DEFAULT NULL, -- depth
  PRIMARY KEY (rack_uuid, device_uuid),
  INDEX idx_reverse (device_uuid, rack_uuid),
  CONSTRAINT rack_device_map_rack
    FOREIGN KEY rack_uuid (rack_uuid)
    REFERENCES inventory_rack (rack_uuid)
    ON DELETE RESTRICT
    ON UPDATE RESTRICT,
  CONSTRAINT rack_device_map_device
    FOREIGN KEY device_uuid (device_uuid)
    REFERENCES inventory_device (device_uuid)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;



CREATE TABLE inventory_physical_entity (
  device_uuid VARBINARY(16) NOT NULL,
  entity_index INT(10) UNSIGNED NOT NULL,
  name VARCHAR(128) DEFAULT NULL,
  alias VARCHAR(255) DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL,
  model_name VARCHAR(128) DEFAULT NULL,
  asset_id VARCHAR(128) DEFAULT NULL,
  parent_index INT(10) UNSIGNED NULL DEFAULT NULL,
  class ENUM(
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
  ) NOT NULL DEFAULT 'unknown',
  relative_position INT(10) NULL DEFAULT NULL,
  revision_hardware VARCHAR(64) DEFAULT NULL,
  revision_firmware VARCHAR(64) DEFAULT NULL,
  revision_software VARCHAR(64) DEFAULT NULL,
  manufacturer_name VARCHAR(64) DEFAULT NULL,
  serial_number VARCHAR(64) DEFAULT NULL,
  field_replaceable_unit ENUM('y','n') DEFAULT NULL, -- really, NULL?
  PRIMARY KEY (device_uuid, entity_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;


CREATE TABLE inventory_physical_entity_sensor (
  device_uuid VARBINARY(16) NOT NULL,
  entity_index INT(10) UNSIGNED NOT NULL,
  sensor_type ENUM( -- entPhySensorType
    'other',        --  1, a measure other than those listed below
    'unknown',      --  2, unknown measurement, or arbitrary, relative numbers
    'voltsAC',      --  3, electric potential
    'voltsDC',      --  4, electric potential
    'amperes',      --  5, electric current
    'watts',        --  6, power
    'hertz',        --  7, frequency
    'celsius',      --  8, temperature
    'percentRH',    --  9, percent relative humidity
    'rpm',          -- 10, shaft revolutions per minute
    'cmm',          -- 11, cubic meters per minute (airflow)
    'truthvalue'    -- 12, value takes { true(1), false(2) }
  ) NOT NULL DEFAULT 'unknown',
  sensor_scale ENUM( -- entPhySensorScale, exponent, SI prefix
    'yocto',   --  1, y
    'zepto',   --  2, z
    'atto',    --  3, a
    'femto',   --  4, f
    'pico',    --  5, p
    'nano',    --  6, n
    'micro',   --  7, µ
    'milli',   --  8, m
    'units',   --  9, -
    -- deka -> da
    -- hekto -> h
    'kilo',    -- 10, k
    'mega',    -- 11, M
    'giga',    -- 12, G
    'tera',    -- 13, T
    'exa',     -- 14, E
    'peta',    -- 15, P
    'zetta',   -- 16, Z
    'yotta'    -- 17, Y
  ) NOT NULL DEFAULT 'units',

  -- there is yotta, ronna (R), quetta (Q) and yocto, ronto (r), quekto (q)
  sensor_precision TINYINT NOT NULL DEFAULT 0, -- ranges from -8 to +9, indicates fractional part of the value
  sensor_status ENUM(
    'ok',             -- 1
    'unavailable',    -- 2
    'nonoperational'  -- 3
  ) NULL DEFAULT NULL,
  sensor_value INT DEFAULT NULL, -- Range: min=-1000000000, max=1000000000, but -min/+max indicates under/overflow
                                 -- must range from 0 to 100 for percentRH
  sensor_units_display VARCHAR(32) DEFAULT NULL,
  PRIMARY KEY (device_uuid, entity_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE inventory_entity_ifmap (
  device_uuid VARBINARY(16) NOT NULL,
  entity_index INT(10) UNSIGNED NOT NULL,
  if_index INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (device_uuid, entity_index)
  -- UNIQUE INDEX ifmap_idx (device_uuid, if_index) -> encountered duplicates
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;





CREATE TABLE snmp_credential (
  -- TODO:
  -- credential_uuid -> uuid VARBINARY(16) NOT NULL,
  -- credential_name -> label VARCHAR(64) NOT NULL,
  credential_uuid VARBINARY(16) NOT NULL,
  credential_name VARCHAR(64) NOT NULL,
  snmp_version ENUM('1','2c','3') NOT NULL,
  security_name VARCHAR(64) NOT NULL COMMENT 'This is the community for v1/v2c and the user for v3',
  -- TODO: Is there a group name?
  security_level ENUM('noAuthNoPriv','authNoPriv','authPriv') NOT NULL DEFAULT 'noAuthNoPriv',
  -- TODO: context_name -> replacement for v2 community name addressing (e.g. different bridge tables)
  --       "mandatory(?), but usually empty"
  auth_protocol ENUM('md5','sha1', 'sha224', 'sha256', 'sha384', 'sha512') DEFAULT NULL,
  auth_key VARCHAR(64) DEFAULT NULL,
  priv_protocol ENUM('des','aes128', 'aes192', 'aes256') DEFAULT NULL, -- TODO: 3DES, DES?
  priv_key VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (credential_uuid),
  UNIQUE KEY credential_name (credential_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE snmp_agent (
  agent_uuid VARBINARY(16) NOT NULL,
  credential_uuid VARBINARY(16) NOT NULL,
  datanode_uuid VARBINARY(16) NULL DEFAULT NULL,
  engine_boots INT(10) NULL DEFAULT NULL,
  ip_address VARBINARY(16) NOT NULL,
  ip_protocol ENUM('ipv4','ipv6') NOT NULL, -- TODO: remove
  snmp_port SMALLINT(5) UNSIGNED NOT NULL DEFAULT '161',
  label VARCHAR(255) NULL DEFAULT NULL,
  engine_id VARBINARY(64) DEFAULT NULL, -- it is absolutely essential, that this is unique! auto-configured by the device
  engine_boot_count BIGINT(20) UNSIGNED DEFAULT NULL, -- required for v3 auth
  engine_boot_time BIGINT(20) UNSIGNED NULL DEFAULT NULL, -- convert sys_uptime?
  sys_name VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  -- Long sys_descr example:
  -- Cisco IOS Software, C3750E Software (C3750E-UNIVERSALK9-M), Version 12.2(55)SE7,
  --  RELEASE SOFTWARE (fc1) Technical Support: http://www.cisco.com/techsupport
  --  Copyright (c) 1986-2013 by Cisco Systems, Inc. Compiled Mon 28-Jan-13 09:55
  --  by prod_rel_team
  sys_descr VARCHAR(255) DEFAULT NULL,
  -- refers products. Cisco example (CISCO-PRODUCTS-MIB):
  -- 1.3.6.1.4.1.9.1.516 = catalyst37xxStack
  --   catalyst37xxStack OBJECT IDENTIFIER ::= { ciscoProducts 516 } -- A stack
  --   of any catalyst37xx stack-able ethernet switches with unified identity
  --   (as a single unified switch), control and management.
  sys_object_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  sys_contact VARCHAR(255) DEFAULT NULL,
  sys_location TEXT DEFAULT NULL,
  sys_services TINYINT(3) UNSIGNED DEFAULT NULL,

  manufacturer_name VARCHAR(64) DEFAULT NULL,
  model_name VARCHAR(128) DEFAULT NULL,
  serial_number VARCHAR(64) DEFAULT NULL,

  -- last_seen DATETIME DEFAULT NULL,
  state ENUM(
    'ok',
    'unreachable',
    'discovered',
    'blacklisted'
  ) NOT NULL,
  PRIMARY KEY (agent_uuid),
  CONSTRAINT snmp_agent_credential
    FOREIGN KEY (credential_uuid)
    REFERENCES snmp_credential (credential_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE snmp_target_health (
  uuid VARBINARY(16) NOT NULL,
  state ENUM('pending', 'reachable', 'failing') NOT NULL,
  PRIMARY KEY (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE snmp_system_info (
  uuid VARBINARY(16) NOT NULL,
  datanode_uuid VARBINARY(16) NOT NULL,

  system_name VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
    -- Long sys_descr example:
    -- Cisco IOS Software, C3750E Software (C3750E-UNIVERSALK9-M), Version 12.2(55)SE7,
    --  RELEASE SOFTWARE (fc1) Technical Support: http://www.cisco.com/techsupport
    --  Copyright (c) 1986-2013 by Cisco Systems, Inc. Compiled Mon 28-Jan-13 09:55
    --  by prod_rel_team
  system_description VARCHAR(255) DEFAULT NULL,
  system_location TEXT DEFAULT NULL,
  system_contact VARCHAR(255) DEFAULT NULL,
  system_services TINYINT(3) UNSIGNED DEFAULT NULL,
  system_oid VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  system_engine_id VARBINARY(64) DEFAULT NULL, -- it is absolutely essential, that this is unique! auto-configured by the device
  system_engine_boot_count BIGINT(20) UNSIGNED DEFAULT NULL, -- required for v3 auth
  system_engine_boot_time BIGINT(20) UNSIGNED NULL DEFAULT NULL, -- convert sys_uptime?
  system_engine_max_message_size INT(10) UNSIGNED NULL DEFAULT NULL,
  dot1d_base_bridge_address VARBINARY(6) NULL DEFAULT NULL,
  -- refers products. Cisco example (CISCO-PRODUCTS-MIB):
  -- 1.3.6.1.4.1.9.1.516 = catalyst37xxStack
  --   catalyst37xxStack OBJECT IDENTIFIER ::= { ciscoProducts 516 } -- A stack
  --   of any catalyst37xx stack-able ethernet switches with unified identity
  --   (as a single unified switch), control and management.

  PRIMARY KEY (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;




CREATE TABLE snmp_discovery_rule (
  uuid VARBINARY(16) NOT NULL,
  label VARCHAR(128) NOT NULL,
  implementation VARCHAR(128) NOT NULL, -- moduleName/ClassName
  settings TEXT NOT NULL, -- json-encoded
  PRIMARY KEY (uuid),
  UNIQUE INDEX (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

-- insert into snmp_discovery_rule (uuid, label, implementation, settings) VALUES (UNHEX(REPLACE(UUID(), '-', '')), 'Nedis Import', 'inventory/NedisImport', '{}');
-- settings: resources_config_file, db_resource_name

CREATE TABLE snmp_discovery_candidate (
  uuid VARBINARY(16) NOT NULL,
  discovery_rule_uuid VARBINARY(16) NOT NULL,
  datanode_uuid VARBINARY(16) NOT NULL,
  credential_uuid VARBINARY(16) NOT NULL,
  ip_address VARBINARY(16) NOT NULL, -- v4: ::ffff:0.0.0.0
  -- TODO: address_family, network_address?
  snmp_port SMALLINT(5) UNSIGNED NOT NULL DEFAULT '161',
  state ENUM('pending', 'reachable', 'failing', 'disabled') NOT NULL, -- todo: remove?
  -- TODO: is_enabled?
  ts_last_reachable BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  ts_last_check BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (uuid),
  UNIQUE INDEX idx_ip_per_rule_and_node (discovery_rule_uuid, datanode_uuid, ip_address),
  INDEX idx_search (state, ts_last_check),
  CONSTRAINT discovery_candidate_rule_uuid
    FOREIGN KEY (discovery_rule_uuid)
    REFERENCES snmp_discovery_rule (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;




CREATE TABLE snmp_interface_config (
  system_uuid VARBINARY(16) NOT NULL,
  if_index INT(10) UNSIGNED DEFAULT NULL,
  if_type MEDIUMINT(8) UNSIGNED NOT NULL,
  if_name VARBINARY(64) DEFAULT NULL, -- really, NULL?
  if_alias VARCHAR(255) DEFAULT NULL,
  if_description VARCHAR(255) DEFAULT NULL,
  physical_address VARBINARY(6) DEFAULT NULL,
  mtu INT UNSIGNED DEFAULT NULL,
  speed_kbit INT(10) UNSIGNED DEFAULT NULL,
  status_admin ENUM('up','down','testing') DEFAULT NULL,
  monitor ENUM('y','n') DEFAULT 'y',
  notify ENUM('y','n') DEFAULT 'n',
  promiscuous_mode ENUM('y','n') NULL DEFAULT NULL,

  -- TODO:
  -- ipv4_enabled ENUM('y','n') NOT NULL,
  -- ipv6_enabled ENUM('y','n') NOT NULL,
  -- ipv4_forwarding ENUM('y','n') NOT NULL,
  -- ipv6_forwarding ENUM('y','n') NOT NULL,
  -- ipv4_mtu SMALLINT UNSIGNED NOT NULL,
  -- ipv6_mtu INT UNSIGNED NOT NULL,

  -- forward_transitions int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (system_uuid, if_index),
  KEY system_uuid (system_uuid),
  KEY if_index (if_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;


CREATE TABLE snmp_interface_status (
  system_uuid VARBINARY(16) NOT NULL,
  if_index INT(10) UNSIGNED DEFAULT NULL,
  status_operational ENUM('up','down','testing','unknown','dormant','notPresent','lowerLayerDown') DEFAULT NULL,
  status_stp ENUM('disabled','blocking','listening','learning','forwarding','broken') DEFAULT NULL,
  status_duplex ENUM('unknown','halfDuplex','fullDuplex') DEFAULT NULL,
  connector_present ENUM('y', 'n') DEFAULT NULL,
  promiscuous_mode ENUM('y', 'n') NULL DEFAULT NULL,
  current_kbit_in INT(10) UNSIGNED DEFAULT NULL,
  current_kbit_out INT(10) UNSIGNED DEFAULT NULL,
  last_update timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  stp_designated_root VARBINARY(8) DEFAULT NULL,
  stp_designated_bridge VARBINARY(8) DEFAULT NULL,
  stp_designated_port VARBINARY(8) NULL DEFAULT NULL,
  stp_forward_transitions INT(10) UNSIGNED DEFAULT NULL,
  stp_port_path_cost MEDIUMINT(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (system_uuid, if_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

-- Changes:
DROP TABLE snmp_agent;
CREATE TABLE snmp_agent (
  agent_uuid VARBINARY(16) NOT NULL,
  credential_uuid VARBINARY(16) NOT NULL,
  datanode_uuid VARBINARY(16) NULL DEFAULT NULL,
  ip_address VARBINARY(16) NOT NULL,
  ip_protocol ENUM('ipv4','ipv6') NOT NULL, -- TODO: remove
  snmp_port SMALLINT(5) UNSIGNED NOT NULL DEFAULT '161',
  label VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (agent_uuid),
  CONSTRAINT snmp_agent_credential
    FOREIGN KEY (credential_uuid)
    REFERENCES snmp_credential (credential_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;


-- After EOLO:


CREATE TABLE system_lifecycle (
  uuid VARBINARY(16) NOT NULL,
  label VARCHAR(64) NOT NULL,
  is_configurable ENUM('y', 'n') NOT NULL,
  is_enabled ENUM('y', 'n') NOT NULL,
  enable_alarming ENUM('y', 'n') NOT NULL,
  enable_monitoring ENUM('y', 'n') NOT NULL,
  enable_discovery ENUM('y', 'n') NOT NULL,
  PRIMARY KEY (uuid),
  UNIQUE INDEX idx_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

-- default list with default custom UUIDv5:
INSERT INTO system_lifecycle VALUES
    -- ns: 5fee96a1-6fa0-4700-936e-02780e8c0ff4

    -- uuidgen --sha1 --namespace 5fee96a1-6fa0-4700-936e-02780e8c0ff4 --name Acquisition
    -- ab238f2d-ea40-5279-8d65-ef21667ed087

    (0xab238f2dea4052798d65ef21667ed087, 'Acquisition',       'n', 'y', 'n', 'n', 'n'),
    (0x6a2aa31b992a574ebc59ea1d6fccc42a, 'Deployment',        'n', 'y', 'n', 'n', 'y'),
    (0xd71539b650f25ccf804f2980b8d8217b, 'Business as usual', 'n', 'y', 'y', 'y', 'y'),
    (0x7d214595d0965032b7de7615e4464b40, 'Maintenance',       'n', 'y', 'n', 'y', 'n'),
    (0x49010e829c8455529ae8e6e57ee269d5, 'Retirement',        'n', 'y', 'n', 'n', 'n');


CREATE TABLE system_environment (
  uuid VARBINARY(16) NOT NULL,
  label VARCHAR(64) NOT NULL,
  is_configurable ENUM('y', 'n') NOT NULL,
  is_enabled ENUM('y', 'n') NOT NULL,
  PRIMARY KEY (uuid),
  UNIQUE INDEX idx_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

INSERT INTO system_environment VALUES
    (0xc6d5e5f05ba35625a7bc7f97fb8a1d97, 'Development', 'n', 'y'),
    -- This is the lowest risk environment, where all the initial development work occurs.
    (0xea92aaacf68257bd8111ea91497f6ebc, 'Testing', 'n', 'y'),
    -- A Test environment is where you test your upgrade procedure against controlled data and perform controlled testing of the resulting Waveset application.
    -- The server where QA team perform the testing with the different input parameter. This allows the team to access the work for verification. The internal team completes the testing phase, usually with the use of a QA Tester. The tester will run various use cases to ensure that the product is functioning as it should. If the tester discovers bugs or other issues, they will create tasks for the developers or programmers to fix.
    (0xb8ac93707916500d8d5f49a769f51ad4, 'Production', 'n', 'y');
    -- Production server is the server where our live data is stored.
    -- After all stages of the testing data goes to live.


ALTER TABLE snmp_agent ADD COLUMN lifecycle_uuid VARBINARY(16) NULL DEFAULT NULL AFTER datanode_uuid;
UPDATE snmp_agent SET lifecycle_uuid = 0x7d214595d0965032b7de7615e4464b40;
ALTER TABLE snmp_agent MODIFY COLUMN lifecycle_uuid VARBINARY(16) NOT NULL;
ALTER TABLE snmp_agent ADD
  CONSTRAINT snmp_agent_lifecycle
    FOREIGN KEY (lifecycle_uuid)
    REFERENCES system_lifecycle (uuid);


ALTER TABLE snmp_agent ADD COLUMN environment_uuid VARBINARY(16) NULL DEFAULT NULL AFTER lifecycle_uuid;
UPDATE snmp_agent SET environment_uuid = 0xb8ac93707916500d8d5f49a769f51ad4;
ALTER TABLE snmp_agent MODIFY COLUMN environment_uuid VARBINARY(16) NOT NULL;
ALTER TABLE snmp_agent ADD
  CONSTRAINT snmp_agent_environment
    FOREIGN KEY (environment_uuid)
    REFERENCES system_environment (uuid);





CREATE TABLE rrd_archive_set (
  uuid VARBINARY(16) NOT NULL, -- uuid5(ns . sha1(..)
  description TEXT DEFAULT NULL,
  -- step_size INT(10) UNSIGNED NOT NULL, -- really? It's in the file!
  PRIMARY KEY (uuid),
  INDEX search_idx (description (128))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;


CREATE TABLE rrd_archive (
  rrd_archive_set_uuid VARBINARY(16) NOT NULL,
  rra_index INT(10) UNSIGNED NOT NULL, -- starts with 0
  consolidation_function ENUM(
      -- Aggregation:
    'AVERAGE',
    'MIN',
    'MAX',
    'LAST',
    -- Forecasting:
    'HWPREDICT',
    'MHWPREDICT',
    'SEASONAL',
    'DEVSEASONAL',
    'DEVPREDICT',
    'FAILURES'
  ),
  -- xfiles_factor FLOAT DEFAULT NULL, -- aggregation only. Defaults to 0.5
  -- steps INT(10) UNSIGNED DEFAULT NULL, -- aggregation only
  row_count INT(10) UNSIGNED DEFAULT NULL,

  definition VARCHAR(255) NOT NULL, -- e.g. RRA:AVERAGE:0.5:1:2880. Interesting for forecasting

  -- defer_creation ENUM('y', 'n') NOT NULL, -- do not create immediately. TODO: transition to other archive?

  PRIMARY KEY (rrd_archive_set_uuid, rra_index),
  CONSTRAINT rrd_archive_set_uuid
  FOREIGN KEY rrd_archive_set (rrd_archive_set_uuid)
  REFERENCES rrd_archive_set (uuid)
    ON DELETE CASCADE
    ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;


CREATE TABLE rrd_datasource_list (
    uuid VARBINARY(16) NOT NULL,
    PRIMARY KEY(uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;


CREATE TABLE rrd_datasource (
  datasource_list_uuid VARBINARY(16) NOT NULL,
  datasource_index INT(10) UNSIGNED NOT NULL, -- starts with 1?
  datasource_name_rrd VARCHAR(19) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
      COMMENT '1 to 19 characters long in the characters [a-zA-Z0-9_]',
  datasource_name VARCHAR(128) NOT NULL, -- long ds name
  datasource_type ENUM(
      'GAUGE',
      'COUNTER',
      'DERIVE',
      'DCOUNTER',
      'DDERIVE',
      'ABSOLUTE',
      'COMPUTE'
  ) NOT NULL,
  minimal_heartbeat INT(10) UNSIGNED NOT NULL COMMENT 'Max seconds before "unknown"',
  min_value DOUBLE DEFAULT NULL,
  max_value DOUBLE DEFAULT NULL,
    -- rpn_expression TEXT DEFAULT NULL, -- always DS:name:type:heartbeat[:min[:max]]
  PRIMARY KEY (datasource_list_uuid, datasource_index),
  UNIQUE INDEX datasource_name_idx (datasource_list_uuid, datasource_name),
  CONSTRAINT rrd_datasource_rrd_file
  FOREIGN KEY rrd_datasource_list (datasource_list_uuid)
  REFERENCES rrd_datasource_list (uuid)
    ON DELETE CASCADE
    ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;


-- measurement = file
CREATE TABLE rrd_file (
  uuid VARBINARY(16) NOT NULL,
  datanode_uuid VARBINARY(16) NULL DEFAULT NULL, -- NOT NULL, -- currently missingin DeferredTableHandler
  metric_store_uuid VARBINARY(16) NOT NULL,
  device_uuid VARBINARY(16) NULL DEFAULT NULL,
  measurement_name VARCHAR(64) NULL DEFAULT NULL,
  instance VARCHAR(255) NULL DEFAULT NULL,
    -- tags TEXT NULL DEFAULT NULL,
  filename VARCHAR(40) NOT NULL,
  -- filename VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  rrd_step INT(10) UNSIGNED NOT NULL COMMENT 'Step size, e.g. 60, 300',
  rrd_version CHAR(4) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  rrd_header_size INT(10) UNSIGNED NULL DEFAULT NULL,
  -- ts_rrd_last_update BIGINT(10) UNSIGNED DEFAULT NULL
  --     COMMENT 'Last SEEN update', -- only from time to time? drop this?
  rrd_datasource_list_checksum VARBINARY(16) NOT NULL, -- TODO: checksum -> uuid
  rrd_archive_set_checksum VARBINARY(16) NOT NULL,

  -- partition, host, ciname... ?
  PRIMARY KEY (uuid),
  -- UNIQUE INDEX file_idx (rrd_instance_id, filename),
  CONSTRAINT rrd_file_rrd_archive_set
    FOREIGN KEY rrd_archive_set (rrd_archive_set_checksum)
    REFERENCES rrd_archive_set (uuid)
      ON DELETE RESTRICT
      ON UPDATE RESTRICT,
  CONSTRAINT rrd_file_rrd_datasource_list
    FOREIGN KEY rrd_datasource_list (rrd_datasource_list_checksum)
    REFERENCES rrd_datasource_list (uuid)
      ON DELETE RESTRICT
      ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;


ALTER TABLE datanode
  ADD COLUMN db_stream_position VARCHAR(21) NULL DEFAULT NULL AFTER label,
  ADD COLUMN db_stream_error TEXT DEFAULT NULL AFTER db_stream_position;
UPDATE datanode SET db_stream_position = '0-0';
ALTER TABLE datanode
  MODIFY COLUMN db_stream_position VARCHAR(21) NOT NULL;

ALTER TABLE snmp_interface_config
  MODIFY COLUMN if_type MEDIUMINT(8) UNSIGNED NULL DEFAULT NULL;


CREATE TABLE data_autonomous_system (
  asn INT(10) UNSIGNED NOT NULL,
  handle VARCHAR(128) NOT NULL,
  description varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (asn),
  INDEX idx_search (handle(64), description(128)),
  INDEX idx_search2 (description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;


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

CREATE TABLE data_ip_country_lite (
  ip_family ENUM('IPv4', 'IPv6') NOT NULL,
  ip_range_from VARBINARY(16) NOT NULL,
  ip_range_to VARBINARY(16) NOT NULL,
  country_code CHAR(2) NOT NULL,
  PRIMARY KEY (ip_family, ip_range_from),
  INDEX idx_additional (ip_family, ip_range_from, ip_range_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- TODO: Do we really need the node UUID here? -> PollInterfaceConfig
ALTER TABLE snmp_interface_config
  ADD COLUMN datanode_uuid VARBINARY(16) NOT NULL AFTER system_uuid;

-- TODO: really?
ALTER TABLE rrd_file
  ADD COLUMN tags TEXT NULL DEFAULT NULL AFTER instance;

CREATE TABLE schema_migration (
  schema_version SMALLINT UNSIGNED NOT NULL,
  component_name VARCHAR(64) NOT NULL,
  migration_time DATETIME NOT NULL,
  PRIMARY KEY(schema_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE daemon_info (
  instance_uuid VARBINARY(16) NOT NULL, -- random by daemon
  schema_version SMALLINT UNSIGNED NOT NULL,
  fqdn VARCHAR(255) NOT NULL,
  username VARCHAR(64) NOT NULL,
  pid INT UNSIGNED NOT NULL,
  binary_path VARCHAR(128) NOT NULL,
  binary_realpath VARCHAR(128) NOT NULL,
  php_binary_path VARCHAR(128) NOT NULL,
  php_binary_realpath VARCHAR(128) NOT NULL,
  php_version VARCHAR(64) NOT NULL,
  php_integer_size SMALLINT NOT NULL,
  running_with_systemd ENUM('y', 'n') NOT NULL,
  ts_started BIGINT(20) NOT NULL,
  ts_stopped BIGINT(20) DEFAULT NULL,
  ts_last_modification BIGINT(20) DEFAULT NULL,
  ts_last_update BIGINT(20) DEFAULT NULL,
  process_info MEDIUMTEXT NOT NULL,
  PRIMARY KEY (instance_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;

INSERT INTO schema_migration
  (schema_version, component_name, migration_time)
VALUES (1, 'inventory', NOW());
