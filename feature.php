<?php

use IMEdge\Node\Feature;
use IcingaFeature\Inventory\ConnectionSubscriber;
use IcingaFeature\Inventory\Db\DbConnection;
use IcingaFeature\Inventory\InventoryRunner;
use IcingaFeature\Inventory\RpcContextInventory;
use IcingaFeature\Inventory\RpcSubscriber;
use Revolt\EventLoop;

require __DIR__ . '/vendor/autoload.php';
/** @var Feature $this */
$settings = $this->settings;
$db = new DbConnection(
    $settings->getRequired('dsn'),
    $settings->getRequired('username'),
    $settings->getRequired('password')
);
// $logger = new \gipfl\Log\PrefixLogger($this->logger)
$runner = new InventoryRunner($this, $db, $this->logger);
EventLoop::queue($runner->run(...)); // Order matters
$this->registerRpcNamespace('inventory', new RpcContextInventory($runner));
$this->subscribeRpcRegistrations(new RpcSubscriber($this->nodeIdentifier, $db, $this->logger));
$this->subscribeConnections(new ConnectionSubscriber($runner, $this->nodeIdentifier, $db, $this->logger));
EventLoop::delay(0.2, function () use ($runner) {
    $this->registerInventory($runner);
});
