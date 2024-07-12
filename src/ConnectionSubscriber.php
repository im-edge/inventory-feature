<?php

namespace IMEdge\InventoryFeature;

use Exception;
use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\JsonRpc\JsonRpcConnection;
use IMEdge\Node\Network\ConnectionSubscriberInterface;
use IMEdge\Node\Rpc\ApiRunner;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Revolt\EventLoop;

use Throwable;

use function Amp\async;
use function Amp\Future\await;

class ConnectionSubscriber implements ConnectionSubscriberInterface
{
    public function __construct(
        protected InventoryRunner $runner,
        protected NodeIdentifier $nodeIdentifier,
        protected DbConnection $db,
        protected LoggerInterface $logger,
    ) {
    }

    public function activateConnection(string $hexUuid, JsonRpcConnection $connection): void
    {
        $this->logger->info('OOOO inventory got ' . $hexUuid);
        // DB -> find related credentials, ship them
        // DB -> find discovery jobs, ship them
        if ($connection->requestHandler === null) {
            $connection->requestHandler = new ApiRunner($this->nodeIdentifier->uuid->toString());
            // $connection->logger = $this->logger; We did so, but this feels wrong
        }
        $connection->requestHandler->addApi(new RemoteInventoryApi($this->runner, $this->logger));
        try {
            $uuid = Uuid::fromString($hexUuid);
            $future = async(function () use ($uuid) {
                return array_map(function ($row) {
                    return SnmpCredential::fromDbRow($row);
                }, CredentialLoader::fetchAllForDataNode($uuid, $this->db));
            });
            [$credentials] = await([$future]);
            try {
                $methods = (array) $connection->request('node.getAvailableMethods');
            } catch (Exception $e) {
                $this->logger->error('Getting feature list failed: ' . $e->getMessage());
                return;
            }

            if (isset($methods['snmp.setCredentials'])) {
                $this->logger->notice(sprintf('Sending %d credentials to %s', count($credentials), $hexUuid));
                try {
                    $connection->request(
                        'snmp.setCredentials',
                        (object)[
                            'credentials' => $credentials,
                        ]
                    );
                } catch (Exception $e) {
                    $this->logger->error('Sending SNMP credentials failed: ' . $e->getMessage());
                }
            } else {
                $this->logger->notice(sprintf('Peer %s has no SNMP support', $hexUuid));
            }

            EventLoop::delay(1, function () use ($connection, $uuid) {
                try {
                    $connection->request('node.setRemoteInventory', (object) [
                        'datanodeUuid'   => $this->nodeIdentifier->uuid->toString(),
                        'tablePositions' => $this->runner->loadTableSyncPositions(new NodeIdentifier($uuid, 'any-peer', 'any-peer.example.com')),
                    ]);

                    $this->logger->notice('SHIPPED table positions');
                } catch (Exception $e) {
                    $this->logger->error('SHIPPING table positions failed: ' . $e->getMessage());
                }
            });

        } catch (Throwable $e) {
            $this->logger->error('Activating connection failed: ' . $e->getMessage());
        }
    }

    public function deactivateConnection(string $hexUuid): void
    {
        $this->logger->info('inventory lost ' . $hexUuid);
        // notify?
    }
}
