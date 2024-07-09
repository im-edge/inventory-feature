<?php

namespace IMEdge\InventoryFeature;

use IMEdge\Node\FeatureRegistration\RpcRegistrationSubscriberInterface;
use IMEdge\SnmpFeature\SnmpCredentials;
use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\Inventory\NodeIdentifier;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

class RpcSubscriber implements RpcRegistrationSubscriberInterface
{
    public function __construct(
        protected NodeIdentifier $nodeIdentifier,
        protected DbConnection $db,
        protected LoggerInterface $logger,
    ) {}

    public function registerRpcNamespace(string $namespace, object $handler): void
    {
        if ($namespace === 'snmp') {
            $this->logger->notice('Inventory RPC subscriber got SNMP feature');
            EventLoop::queue(function () use ($handler) {
                $this->shipLocalSnmpCredentials($handler);
            });
        }
    }

    protected function shipLocalSnmpCredentials($handler): void
    {
        $credentials = CredentialLoader::fetchAllForDataNode($this->nodeIdentifier->uuid, $this->db);
        foreach ($credentials as &$row) {
            $row = SnmpCredential::fromDbRow($row)->jsonSerialize();
        }
        $handler->setCredentialsRequest(SnmpCredentials::fromSerialization($credentials));
        $this->logger->notice('Local SNMP credentials done');
    }
}
