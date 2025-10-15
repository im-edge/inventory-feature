<?php

namespace IMEdge\InventoryFeature;

use IMEdge\InventoryFeature\Db\DbConnection;
use Ramsey\Uuid\UuidInterface;

class CredentialLoader
{
    public static function fetchAllForDataNode(UuidInterface $uuid, DbConnection $db): array
    {
        $sql1 = 'SELECT DISTINCT c.* FROM snmp_credential c'
            . ' JOIN snmp_agent a ON a.credential_uuid = c.credential_uuid'
            . ' WHERE a.datanode_uuid = ' . self::escapeBinary($uuid->getBytes());
        $sql2 = 'SELECT DISTINCT c.* FROM snmp_credential c'
            . ' JOIN snmp_discovery_candidate dc ON dc.credential_uuid = c.credential_uuid'
            . ' WHERE dc.datanode_uuid = ' . self::escapeBinary($uuid->getBytes());
        $sql = "SELECT * FROM ($sql1 UNION ALL $sql2) sub GROUP BY credential_uuid";

        return $db->fetchAll($sql);
    }

    public static function fetchAll(DbConnection $db): array
    {
        $sql1 = 'SELECT DISTINCT a.datanode_uuid, c.* FROM snmp_credential c'
            . ' JOIN snmp_agent a ON a.credential_uuid = c.credential_uuid';
        $sql2 = 'SELECT DISTINCT dc.datanode_uuid, c.* FROM snmp_credential c'
            . ' JOIN snmp_discovery_candidate dc ON dc.credential_uuid = c.credential_uuid';
        $sql = "SELECT * FROM ($sql1 UNION ALL $sql2) sub GROUP BY datanode_uuid, credential_uuid";

        return $db->fetchAll($sql);
    }

    protected static function escapeBinary(string $binary): string
    {
        return '0x' . bin2hex($binary);
    }
}
