<?php

namespace IMEdge\InventoryFeature;

use Amp\Socket\InternetAddress;
use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\SnmpFeature\SnmpCredentials;
use IMEdge\SnmpFeature\SnmpScenario\SnmpTarget;
use IMEdge\SnmpFeature\SnmpScenario\SnmpTargets;
use IMEdge\SnmpFeature\SnmpScenario\TargetState;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Loads data for local and remote nodes with an enabled SNMP feature
 *
 * We should find a clean way to hook such logic
 */
class SnmpFeatureLoader
{
    public static function fetchCredentials(UuidInterface $nodeUuid, DbConnection $db): SnmpCredentials
    {
        $sql1 = 'SELECT DISTINCT c.* FROM snmp_credential c'
            . ' JOIN snmp_agent a ON a.credential_uuid = c.credential_uuid'
            . ' WHERE a.datanode_uuid = ' . self::escapeBinary($nodeUuid->getBytes());
        $sql2 = 'SELECT DISTINCT c.* FROM snmp_credential c'
            . ' JOIN snmp_discovery_candidate dc ON dc.credential_uuid = c.credential_uuid'
            . ' WHERE dc.datanode_uuid = ' . self::escapeBinary($nodeUuid->getBytes());
        $sql = "SELECT * FROM ($sql1 UNION ALL $sql2) sub GROUP BY credential_uuid";

        return SnmpCredentials::fromSerialization($db->fetchAll($sql));
    }

    public static function fetchTargets(UuidInterface $nodeUuid, DbConnection $db): SnmpTargets
    {
        $sql = 'SELECT'
            . ' a.agent_uuid, a.ip_address, a.snmp_port, a.credential_uuid, sth.state'
            . ' FROM snmp_agent a'
            . " JOIN system_lifecycle lc ON lc.uuid = a.lifecycle_uuid"
            . " AND (lc.enable_monitoring = 'y' OR lc.enable_discovery = 'y')"
            . ' LEFT JOIN snmp_target_health sth ON sth.uuid = a.agent_uuid'
            . ' WHERE a.datanode_uuid = ' . self::escapeBinary($nodeUuid->getBytes())
            . ' ORDER BY ip_address';

        $targets = [];

        foreach ($db->fetchAll($sql) as $row) {
            $targets[] = new SnmpTarget(
                identifier: Uuid::fromBytes($row['agent_uuid'])->toString(),
                address: new InternetAddress(inet_ntop($row['ip_address']), $row['snmp_port']),
                credentialUuid: Uuid::fromBytes($row['credential_uuid']),
                state: $row['state'] ? TargetState::from($row['state']) : TargetState::PENDING,
                // TODO: persist capabilities
            );
        }

        return new SnmpTargets($targets);
    }

    protected static function escapeBinary(string $binary): string
    {
        return '0x' . bin2hex($binary);
    }
}
