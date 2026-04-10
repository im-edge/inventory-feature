<?php

/**
 * This is an IMEdge Node feature
 *
 * @var Feature $this
 */
use IMEdge\Node\Feature;
use IMEdge\InventoryFeature\ConnectionSubscriber;
use IMEdge\InventoryFeature\InventoryRunner;

$settings = $this->settings;

$runner = new InventoryRunner($this, $this->logger);
$this->onShutdown($runner->stop(...));
$this->subscribeConnections(new ConnectionSubscriber($runner, $this->nodeIdentifier, $this->logger));
$this->onFeaturesReady($runner->onFeaturesReady(...));
$runner->run();
$this->registerRpcApi($runner);
