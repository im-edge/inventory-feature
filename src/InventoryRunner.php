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

    public function __construct(
        public readonly Feature $feature,
        protected readonly DbConnection $db,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function run(): void
    {
        $this->streamer = $this->feature->workerInstances->launchWorker('inventory-streamer', Uuid::uuid4());
        $this->streamer->run(InventoryStreamer::class, $this->feature->settings);
        $this->registerNode($this->feature->nodeIdentifier->uuid, $this->feature->nodeIdentifier->name);
    }

    public function stop(): void
    {
        $this->streamer?->stop();
        $this->streamer = null;
    }

    public function registerNode(UuidInterface $uuid, string $name): void
    {
        $binaryUuid = $uuid->getBytes();
        $current = $this->db->fetchRow('SELECT uuid FROM datanode WHERE uuid = ?', [$binaryUuid]);
        if ($current === null) {
            $this->db->insert('datanode', [
                'uuid'  => $binaryUuid,
                'label' => $name,
                'db_stream_position' => '0-0',
            ]);
            $this->logger->notice(sprintf('%s has been registered in the database', Application::PROCESS_NAME));
        }
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

    #[ApiMethod]
    public function shipLocalSnmpCredentials(): bool
    {
        return $this->snmpApi->setCredentials($this->fetchSnmpCredentials());
    }

    #[ApiMethod]
    public function shipLocalSnmpTargets(): bool
    {
        return $this->snmpApi->setKnownTargets($this->fetchSnmpTargets());
    }

    protected function fetchSnmpCredentials(): SnmpCredentials
    {
        if ($this->streamer === null) {
            throw new RuntimeException('InventoryRunner has no inventoryStreamer');
        }
        // Why not:
        // return $this->streamer->jsonRpc->request('inventoryStreamer.fetchSnmpCredentials');
        return SnmpCredentials::fromSerialization(
            $this->streamer->jsonRpc->request('inventoryStreamer.fetchSnmpCredentials')
        );
    }

    protected function fetchSnmpTargets(): SnmpTargets
    {
        if ($this->streamer === null) {
            throw new RuntimeException('InventoryRunner has no inventoryStreamer');
        }
        // Why not:
        // return $this->streamer->jsonRpc->request('inventoryStreamer.fetchSnmpTargets');
        return SnmpTargets::fromSerialization($this->streamer->jsonRpc->request('inventoryStreamer.fetchSnmpTargets'));
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
