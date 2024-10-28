<?php

namespace IMEdge\InventoryFeature;

use IMEdge\Config\Settings;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Node\ApplicationContext;
use IMEdge\Node\ImedgeWorker;
use Psr\Log\LoggerInterface;

class InventoryStreamer implements ImedgeWorker
{
    protected ?DbStreamWriter $writer = null;
    protected ?DbStreamReader $reader = null;

    public function __construct(
        protected readonly Settings $settings,
        protected readonly NodeIdentifier $nodeIdentifier,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function start(): void
    {
        $this->writer = new DbStreamWriter(
            $this->logger,
            $this->nodeIdentifier->uuid,
            $this->settings->getRequired('dsn'),
            $this->settings->getRequired('username'),
            $this->settings->getRequired('password')
        );
        $this->reader = new DbStreamReader(
            ApplicationContext::getRedisSocket(),
            $this->writer,
            $this->nodeIdentifier->uuid,
            $this->logger
        );
        $this->reader->start();
    }

    public function stop(): void
    {
        $this->reader->stop();
        $this->reader = null;
        $this->writer = null;
    }
}
