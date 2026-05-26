<?php

/**
 * This is an IMEdge Node feature
 *
 * @var Feature $this
 */
use IMEdge\Node\Feature;
use IMEdge\InventoryFeature\InventoryRunner;

$settings = $this->settings;

$runner = new InventoryRunner($this, $this->logger);
$this->onShutdown($runner->stop(...));
$this->subscribeConnections($runner->connectionSubscriber);
$this->onFeaturesReady($runner->onFeaturesReady(...));
$runner->run();
$this->registerRpcApi($runner);
