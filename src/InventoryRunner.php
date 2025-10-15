<?php

namespace IMEdge\InventoryFeature;

use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\Node\Application;
use IMEdge\Node\Feature;
use IMEdge\Node\Worker\WorkerInstance;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Revolt\EventLoop;

class InventoryRunner
{
    protected bool $logActivities = true;
    protected ?WorkerInstance $worker = null;

    public function __construct(
        public readonly Feature $feature,
        protected readonly DbConnection $db,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function run(): void
    {
        $this->worker = $this->feature->workerInstances->launchWorker('inventory-db', Uuid::uuid4());
        $this->worker->run(InventoryStreamer::class, $this->feature->settings);
        EventLoop::delay(1.5, $this->shipLocalSnmpCredentials(...));
        $this->registerNode($this->feature->nodeIdentifier->uuid, $this->feature->nodeIdentifier->name);
    }

    public function stop(): void
    {
        $this->worker?->stop();
        $this->worker = null;
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

    public function shipLocalSnmpCredentials(): void
    {
        // TODO: redesign this
        if ($api = $this->getLocalSnmpApi()) {
            $this->logger->notice('InventoryRunner found SNMP when starting up, loading credentials');
            $localCredentials = CredentialLoader::fetchAllForDataNode($this->feature->nodeIdentifier->uuid, $this->db);
            try {
                $api->setCredentials($localCredentials);
            } catch (\Exception $e) {
                $this->logger->error('Sending SNMP credentials failed (InventoryRunner): ' . $e->getMessage());
            }
        } else {
            $this->logger->notice('InventoryRunner found no SNMP when starting up');
        }
    }

    protected function getLocalSnmpApi(): ?object
    {
        foreach ($this->feature->getRegisteredRpcApis() as $api) {
            // TODO: Check reflection -> ApiNamespace
            if (method_exists($api, 'setCredentialsRequest')) {
                return $api;
            }
        }

        return null;
    }
}
