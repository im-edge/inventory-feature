<?php

namespace IMEdge\InventoryFeature;

use IMEdge\Config\Settings;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\InventoryFeature\Db\DbHandler;
use IMEdge\Node\ApplicationContext;
use IMEdge\Node\ImedgeWorker;
use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;
use IMEdge\SnmpFeature\SnmpCredentials;
use IMEdge\SnmpFeature\SnmpScenario\SnmpTargets;
use Psr\Log\LoggerInterface;

#[ApiNamespace('inventoryStreamer')]
class InventoryStreamer implements ImedgeWorker
{
    protected ?DbStreamWriter $writer = null;
    protected ?DbStreamReader $reader = null;
    protected ?SnmpFeatureLoader $loader = null;
    protected DbHandler $dbHandler;

    public function __construct(
        protected readonly Settings $settings,
        protected readonly NodeIdentifier $nodeIdentifier,
        protected readonly LoggerInterface $logger,
    ) {
    }

    #[ApiMethod]
    public function fetchSnmpCredentials(): SnmpCredentials
    {
        return $this->loader->fetchCredentials($this->nodeIdentifier->uuid);
    }

    #[ApiMethod]
    public function fetchSnmpTargets(): SnmpTargets
    {
        return $this->loader->fetchTargets($this->nodeIdentifier->uuid);
    }

    public function getApiInstances(): array
    {
        return [$this];
    }

    public function start(): void
    {
        $this->dbHandler = new DbHandler(
            $this->settings->getRequired('dsn'),
            $this->settings->getRequired('username'),
            $this->settings->getRequired('password'),
            $this->logger,
        );
        $this->writer = new DbStreamWriter(
            $this->logger,
            $this->nodeIdentifier->uuid,
            $this->settings->getRequired('dsn'),
            $this->settings->getRequired('username'),
            $this->settings->getRequired('password')
        );
        $this->dbHandler->register($this->writer);
        $this->loader = new SnmpFeatureLoader();
        $this->dbHandler->register($this->loader);
        $this->reader = new DbStreamReader(
            ApplicationContext::getRedisSocket(),
            $this->writer,
            $this->nodeIdentifier->uuid,
            $this->logger
        );
        $this->dbHandler->run();
        $this->reader->start();
    }

    public function stop(): void
    {
        $this->reader->stop();
        $this->dbHandler->disconnect();
        $this->reader = null;
        $this->writer = null;
        $this->loader = null;
    }
}
