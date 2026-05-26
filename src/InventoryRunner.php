<?php

namespace IMEdge\InventoryFeature;

use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\Node\Application;
use IMEdge\Node\Feature;
use IMEdge\Node\Features;
use IMEdge\Node\Worker\WorkerInstance;
use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;
use IMEdge\SnmpFeature\SnmpApi;
use IMEdge\SnmpFeature\SnmpCredentials;
use IMEdge\SnmpFeature\SnmpScenario\SnmpTargets;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

#[ApiNamespace('inventory')]
class InventoryRunner
{
    protected bool $logActivities = true;
    protected ?WorkerInstance $streamer = null;
    protected SnmpApi $snmpApi;
    public readonly ConnectionSubscriber $connectionSubscriber;

    public function __construct(
        public readonly Feature $feature,
        protected readonly LoggerInterface $logger,
    ) {
        $this->connectionSubscriber = new ConnectionSubscriber($this, $this->feature->nodeIdentifier, $this->logger);
    }

    public function run(): void
    {
        $this->streamer = $this->feature->workerInstances->launchWorker('inventory-streamer', Uuid::uuid4());
        $this->streamer->run(InventoryStreamer::class, $this->feature->settings);
        $this->streamer->jsonRpc->request('inventoryStreamer.registerNode', [
            $this->feature->nodeIdentifier->uuid,
            $this->feature->nodeIdentifier->name
        ]);
    }

    public function stop(): void
    {
        $this->streamer?->stop();
        $this->streamer = null;
    }

    public function registerNode(UuidInterface $uuid, string $name): void
    {
        $this->streamer->jsonRpc->request('inventoryStreamer.registerNode', [
            $uuid,
            $name
        ]);
    }

    public function onFeaturesReady(Features $features): void
    {
        foreach ($features->getLoaded() as $feature) {
            if ($feature->name === 'snmp') {
                foreach ($feature->getRegisteredRpcApis() as $api) {
                    if ($api instanceof SnmpApi) {
                        $this->foundLocalSnmpApi($api);
                    }
                }
            }
        }
    }

    // TODO: same for remote
    #[ApiMethod]
    public function shipConfigForLocalFeatures(): bool
    {
        return $this->shipLocalSnmpCredentials() && $this->shipLocalSnmpTargets();
    }

    // TODO: same for remote
    #[ApiMethod]
    public function shipConfigForConnectedPeers(): bool
    {
        foreach ($this->connectionSubscriber->getPeers() as $id => $connection) {
            $uuid = Uuid::fromString($id);
            $methods = (array)$connection->request('node.getAvailableMethods');

            if (isset($methods['snmp.setCredentials'])) {
                try {
                    $connection->request('snmp.setCredentials', (object) [
                        'credentials' => $this->fetchSnmpCredentials($uuid),
                    ]);
                    $connection->request('snmp.setKnownTargets', (object) [
                        'targets' => $this->fetchSnmpTargets($uuid),
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error(sprintf(
                        'Sending SNMP credentials to %s failed: %s',
                        $id,
                        $e->getMessage()
                    ));
                }
            }
        }

        return $this->shipLocalSnmpCredentials() && $this->shipLocalSnmpTargets();
    }

    #[ApiMethod]
    public function shipLocalSnmpCredentials(): bool
    {
        return $this->snmpApi->setCredentials($this->fetchSnmpCredentials($this->feature->nodeIdentifier->uuid));
    }

    #[ApiMethod]
    public function shipLocalSnmpTargets(): bool
    {
        return $this->snmpApi->setKnownTargets($this->fetchSnmpTargets($this->feature->nodeIdentifier->uuid));
    }

    #[ApiMethod]
    public function fetchSnmpCredentials(UuidInterface $nodeUuid): SnmpCredentials
    {
        if ($this->streamer === null) {
            throw new RuntimeException('InventoryRunner has no inventoryStreamer');
        }
        // Why not:
        // return $this->streamer->jsonRpc->request('inventoryStreamer.fetchSnmpCredentials');
        return SnmpCredentials::fromSerialization(
            $this->streamer->jsonRpc->request('inventoryStreamer.fetchSnmpCredentials', [$nodeUuid])
        );
    }

    #[ApiMethod]
    public function fetchSnmpTargets(UuidInterface $nodeUuid): SnmpTargets
    {
        if ($this->streamer === null) {
            throw new RuntimeException('InventoryRunner has no inventoryStreamer');
        }
        // Why not:
        // return $this->streamer->jsonRpc->request('inventoryStreamer.fetchSnmpTargets');
        return SnmpTargets::fromSerialization(
            $this->streamer->jsonRpc->request('inventoryStreamer.fetchSnmpTargets', [$nodeUuid])
        );
    }

    protected function foundLocalSnmpApi(SnmpApi $api): void
    {
        $this->snmpApi = $api;
        if ($this->streamer === null) {
            $this->logger->notice('InventoryRunner found SNMP API, but has not DB worker');
            return;
        }
        $rpc = $this->streamer->jsonRpc;
        // SnmpFeatureLoader::fetchCredentials($this->feature->nodeIdentifier->uuid, $this->db)
        $this->logger->debug('InventoryRunner found SNMP API once all features got loaded');
        $this->shipConfigForLocalFeatures();
    }
}
