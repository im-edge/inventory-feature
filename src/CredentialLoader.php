<?php

namespace IMEdge\InventoryFeature;

use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\SnmpFeature\SnmpCredentials;
use Ramsey\Uuid\UuidInterface;

class CredentialLoader
{
    public static function fetchAllForDataNode(UuidInterface $uuid, DbConnection $db): SnmpCredentials
    {
        $sql1 = 'SELECT DISTINCT c.* FROM snmp_credential c'
            . ' JOIN snmp_agent a ON a.credential_uuid = c.credential_uuid'
            . ' WHERE a.datanode_uuid = ' . self::escapeBinary($uuid->getBytes());
        $sql2 = 'SELECT DISTINCT c.* FROM snmp_credential c'
            . ' JOIN snmp_discovery_candidate dc ON dc.credential_uuid = c.credential_uuid'
            . ' WHERE dc.datanode_uuid = ' . self::escapeBinary($uuid->getBytes());
        $sql = "SELECT * FROM ($sql1 UNION ALL $sql2) sub GROUP BY credential_uuid";

        return SnmpCredentials::fromSerialization($db->fetchAll($sql));
    }

    protected static function escapeBinary(string $binary): string
    {
        return '0x' . bin2hex($binary);
    }
}
