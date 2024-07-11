<?php

namespace IMEdge\InventoryFeature;

use IMEdge\Inventory\InventoryAction;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;

class RpcContextRemoteInventory
{
    public function __construct(
        protected readonly InventoryRunner $runner,
        protected readonly LoggerInterface $logger,
    ) {
        $this->logger->notice('RPC Context remote inventory is ready');
    }

    /**
     * @param array $actions
     */
    public function shipBulkActionsRequest(array $actions): bool
    {
        $this->logger->notice(sprintf('Got %d remote bulk actions', count($actions)));
        try {
            foreach ($actions as &$action) {
                if (! $action instanceof InventoryAction) {
                    $action = InventoryAction::fromSerialization($action);
                }
            }

            $this->runner->shipBulkActions($actions);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }
}