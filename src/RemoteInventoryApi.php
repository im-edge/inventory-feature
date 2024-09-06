<?php

namespace IMEdge\InventoryFeature;

use IMEdge\Inventory\InventoryAction;
use IMEdge\RpcApi\ApiMethod;
use IMEdge\RpcApi\ApiNamespace;
use Psr\Log\LoggerInterface;

#[ApiNamespace('inventory')]
class RemoteInventoryApi
{
    public function __construct(
        protected readonly InventoryRunner $runner,
        protected readonly LoggerInterface $logger,
    ) {
        $this->logger->notice('RPC Context remote inventory is ready');
    }

    #[ApiMethod]
    public function shipBulkActionsRequest(array $actions): bool
    {
        throw new \RuntimeException('remote shipBulkActions is no longer supported');
        $this->logger->notice(sprintf('Got %d remote bulk actions', count($actions)));
        try {
            // TODO: ActionList as type?
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
