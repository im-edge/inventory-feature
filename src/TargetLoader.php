<?php

namespace IMEdge\InventoryFeature;

use Amp\Socket\InternetAddress;
use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\SnmpFeature\Capability\CapabilitySet;
use IMEdge\SnmpFeature\SnmpScenario\SnmpTarget;
use IMEdge\SnmpFeature\SnmpScenario\SnmpTargets;
use IMEdge\SnmpFeature\SnmpScenario\TargetState;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class TargetLoader
{
    public static function fetchAllForDataNode(UuidInterface $uuid, DbConnection $db): SnmpTargets
    {
        $sql = 'SELECT'
            . ' a.agent_uuid, a.ip_address, a.snmp_port, a.credential_uuid, sth.state'
            . ' FROM snmp_agent a'
            . " JOIN system_lifecycle lc ON lc.uuid = a.lifecycle_uuid"
            . " AND (lc.enable_monitoring = 'y' OR lc.enable_discovery = 'y')"
            . ' LEFT JOIN snmp_target_health sth ON sth.uuid = a.agent_uuid'
            . ' WHERE a.datanode_uuid = ' . self::escapeBinary($uuid->getBytes())
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
