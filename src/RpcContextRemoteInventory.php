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
     * @return PromiseInterface
     */
    public function shipBulkActionsRequest(array $actions): PromiseInterface
    {
        $this->logger->notice(sprintf('Got %d remote bulk actions', count($actions)));
        try {
            foreach ($actions as &$action) {
                if (! $action instanceof InventoryAction) {
                    $action = InventoryAction::fromSerialization($action);
                }
            }

            return $this->runner->shipBulkActions($actions);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }
}
