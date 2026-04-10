<?php

namespace IMEdge\InventoryFeature;

use Exception;
use IMEdge\Config\Settings;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\InventoryFeature\Db\DbBasedComponent;
use IMEdge\InventoryFeature\Db\DbHandler;
use IMEdge\Node\ApplicationContext;
use IMEdge\Node\ImedgeWorker;
use IMEdge\PDO\PDO;
use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;
use IMEdge\SnmpFeature\SnmpCredentials;
use IMEdge\SnmpFeature\SnmpScenario\SnmpTargets;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Throwable;

#[ApiNamespace('inventoryStreamer')]
class InventoryStreamer implements ImedgeWorker, DbBasedComponent
{
    protected ?DbStreamWriter $writer = null;
    protected ?DbStreamReader $reader = null;
    protected ?SnmpFeatureLoader $loader = null;
    protected DbHandler $dbHandler;
    protected bool $hasDb = false;
    protected array $nodesToRegister = [];

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

    #[ApiMethod]
    public function registerNode(UuidInterface $uuid, string $name): void
    {
        $this->nodesToRegister[$uuid->toString()] = [$uuid, $name];
        if ($this->hasDb) {
            try {
                $this->loader->registerNode($uuid, $name);
            } catch (Exception) {
            }
        }
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
        $this->loader = new SnmpFeatureLoader($this->logger);
        $this->dbHandler->register($this->loader);
        $this->dbHandler->register($this);
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

    public function initDb(PDO $db): void
    {
        foreach ($this->nodesToRegister as $node) {
            try {
                $this->loader->registerNode($node[0], $node[1]);
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }

    public function stopDb(): void
    {
        $this->hasDb = false;
    }
}
