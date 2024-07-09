<?php

namespace IMEdge\InventoryFeature;

use gipfl\Protocol\JsonRpc\Handler\NamespacedPacketHandler;
use gipfl\Protocol\JsonRpc\JsonRpcConnection;
use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Node\Network\ConnectionSubscriberInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\Future\await;

class ConnectionSubscriber implements ConnectionSubscriberInterface
{
    public function __construct(
        protected InventoryRunner $runner,
        protected NodeIdentifier $nodeIdentifier,
        protected DbConnection $db,
        protected LoggerInterface $logger,
    ) {}

    public function activateConnection(string $hexUuid, JsonRpcConnection $connection): void
    {
        $this->logger->info('OOOO inventory got ' . $hexUuid);
        // DB -> find related credentials, ship them
        // DB -> find discovery jobs, ship them
        $rpcHandler = $connection->getHandler();
        $connection->setLogger($this->logger);
        if ($rpcHandler === null) {
            $rpcHandler = new NamespacedPacketHandler();
            $connection->setHandler($rpcHandler);
        } elseif (! $rpcHandler instanceof NamespacedPacketHandler) {
            $this->logger->error('Cannot activate connection, unsupported Packet handler: ' . get_class($rpcHandler));
            return;
        }
        try {
            $rpcHandler->registerNamespace('remoteInventory', new RpcContextRemoteInventory($this->runner, $this->logger));
            $uuid = Uuid::fromString($hexUuid);
            $future = async(function () use ($uuid) {
                return array_map(function ($row) {
                    return SnmpCredential::fromDbRow($row);
                }, CredentialLoader::fetchAllForDataNode($uuid, $this->db));
            });
            [$credentials] = await([$future]);
            $connection->request('datanode.getAvailableMethods')
                ->then(function ($methods) use ($connection, $credentials, $hexUuid) {
                    $methods = (array) $methods;
                    if (isset($methods['snmp.setCredentials'])) {
                        $this->logger->notice(sprintf('Sending %d credentials to %s', count($credentials), $hexUuid));
                        $connection->request('snmp.setCredentials', (object) [
                            'credentials' => $credentials,
                        ])->catch(function (\Exception $e) {
                            $this->logger->error('Sending SNMP credentials failed: ' . $e->getMessage());
                        });
                    } else {
                        $this->logger->notice(sprintf('Peer %s has no SNMP support', $hexUuid));
                    }
                })->catch(function (\Exception $e) {
                    $this->logger->error('Getting feature list failed: ' . $e->getMessage());
                });

            EventLoop::delay(1, function () use ($connection, $uuid) {
                $connection->request('datanode.setRemoteInventory', (object) [
                    'datanodeUuid'   => $this->nodeIdentifier->uuid->toString(),
                    'tablePositions' => $this->runner->loadTableSyncPositions(new NodeIdentifier($uuid, 'any-peer', 'any-peer.example.com')),
                ])->then(function () {
                    $this->logger->notice('SHIPPED table positions');
                }, function (\Exception $e) {
                    $this->logger->error('SHIPPING table positions failed: ' . $e->getMessage());
                });
            });

        } catch (\Throwable $e) {
            $this->logger->error('Activating connection failed: ' . $e->getMessage());
        }
    }

    public function deactivateConnection(string $hexUuid): void
    {
        $this->logger->info('inventory lost ' . $hexUuid);
        // notify?
    }
}
