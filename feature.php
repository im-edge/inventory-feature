<?php

use IMEdge\Node\Feature;
use IMEdge\InventoryFeature\ConnectionSubscriber;
use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\InventoryFeature\InventoryRunner;
use IMEdge\InventoryFeature\RpcSubscriber;
use Revolt\EventLoop;

require __DIR__ . '/vendor/autoload.php';
/** @var Feature $this */
$settings = $this->settings;

$db = new DbConnection(
    preg_replace('#^mysql:#', '', $settings->getRequired('dsn')),
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
