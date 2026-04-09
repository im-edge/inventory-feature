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

$settings = $this->settings;

$db = new DbConnection(
    preg_replace('#^mysql:#', '', $settings->getRequired('dsn')),
    $settings->getRequired('username'),
    $settings->getRequired('password')
);

$runner = new InventoryRunner($this, $db, $this->logger);
$this->onShutdown($runner->stop(...));
$this->subscribeConnections(new ConnectionSubscriber($runner, $this->nodeIdentifier, $db, $this->logger));
$this->onFeaturesReady($runner->onFeaturesReady(...));
$runner->run();
$this->registerRpcApi($runner);
