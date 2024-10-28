<?php

/**
 * This is an IMEdge Node feature
 *
 * @var Feature $this
 */

use IMEdge\Node\Feature;
use IMEdge\InventoryFeature\ConnectionSubscriber;
use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\InventoryFeature\InventoryRunner;
use IMEdge\InventoryFeature\RpcSubscriber;
use Revolt\EventLoop;

require __DIR__ . '/vendor/autoload.php';
$settings = $this->settings;

$db = new DbConnection(
    preg_replace('#^mysql:#', '', $settings->getRequired('dsn')),
    $settings->getRequired('username'),
    $settings->getRequired('password')
);

$runner = new InventoryRunner($this, $db, $this->logger);
EventLoop::queue($runner->run(...)); // Order matters
$this->subscribeRpcRegistrations(new RpcSubscriber($this->nodeIdentifier, $db, $this->logger));
$this->subscribeConnections(new ConnectionSubscriber($runner, $this->nodeIdentifier, $db, $this->logger));
