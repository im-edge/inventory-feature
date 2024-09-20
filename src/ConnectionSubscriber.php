<?php

namespace IMEdge\InventoryFeature;

use Amp\ByteStream\ClosedException;
use Amp\Redis\Protocol\QueryException;
use Exception;
use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\JsonRpc\JsonRpcConnection;
use IMEdge\Node\Network\ConnectionSubscriberInterface;
use IMEdge\Node\Rpc\ApiRunner;
use IMEdge\Node\Rpc\RpcPeerType;
use IMEdge\RedisUtils\RedisResult;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Revolt\EventLoop;
use Throwable;

class ConnectionSubscriber implements ConnectionSubscriberInterface
{
    protected array $gotPeers = [];

    public function __construct(
        protected InventoryRunner $runner,
        protected NodeIdentifier $nodeIdentifier,
        protected DbConnection $db,
        protected LoggerInterface $logger,
    ) {
    }

    public function activateConnection(string $hexUuid, JsonRpcConnection $connection, RpcPeerType $peerType): void
    {
        if (isset($this->gotPeers[$hexUuid])) {
            $this->logger->notice('Avoiding connection from being activated twice. This is a bug');
            return;
        }
        $this->gotPeers[$hexUuid] = $connection;

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
            try {
                $name = $connection->request('node.getName');
                $methods = (array) $connection->request('node.getAvailableMethods');
                if (! str_contains($name, '/')) { // Not so nice way to skip sub-processes for now
                    $this->runner->registerNode($uuid, $name);
                }
            } catch (Exception $e) {
                $this->logger->error('Getting feature list failed: ' . $e->getMessage());
                return;
            }

            if (isset($methods['snmp.setCredentials'])) {
                $credentials = array_map(function ($row) {
                    return SnmpCredential::fromDbRow($row);
                }, CredentialLoader::fetchAllForDataNode($uuid, $this->db));
                $this->logger->notice(sprintf('Sending %d credentials to %s', count($credentials), $hexUuid));
                try {
                    $connection->request('snmp.setCredentials', (object) [
                        'credentials' => $credentials,
                    ]);
                } catch (Exception $e) {
                    $this->logger->error('Sending SNMP credentials failed: ' . $e->getMessage());
                }
            } else {
                $this->logger->notice(sprintf('Peer %s has no SNMP support', $hexUuid));
            }

            if (isset($methods['node.streamDbChanges'])) {
                EventLoop::delay(1, function () use ($connection, $uuid) {
                    $redis = $this->runner->feature->services->getRedisClient('IMEdge/dbStreamReplication');
                    $streamName = 'db-stream-' . $uuid->toString();
                    try {
                        $currentPosition = RedisResult::toHash(
                            $redis->execute('XINFO', 'STREAM', $streamName)
                        )->{'last-entry'}[0];
                    } catch (QueryException $e) {
                        if (str_contains($e->getMessage(), 'no such key')) {
                            $currentPosition = '0-0';
                        } else {
                            throw $e;
                        }
                    }

                    EventLoop::queue(function () use ($redis, $connection, $uuid, $currentPosition) {
                        $this->pollRemoteStream($redis, $connection, $uuid, $currentPosition);
                    });
                });
            }

        } catch (Throwable $e) {
            $this->logger->error('Activating connection failed: ' . $e->getMessage());
        }
    }

    protected function pollRemoteStream(
        $redis,
        JsonRpcConnection $connection,
        UuidInterface $uuid,
        string $currentPosition
    ): void {
        try {
            $remoteRows = $connection->request('node.streamDbChanges', [$currentPosition]);
        } catch (ClosedException $e) {
            $this->logger->notice('Stopping remote stream polling: ' . $e->getMessage());
            return;
        } catch (Exception $e) {
            $this->logger->notice('Polling remote stream failed: ' . $e->getMessage());
            EventLoop::delay(2, function () use ($redis, $connection, $uuid, $currentPosition) {
                $this->pollRemoteStream($redis, $connection, $uuid, $currentPosition);
            });
            return;
        }
        if ($remoteRows === null) {
            EventLoop::delay(2, function () use ($redis, $connection, $uuid, $currentPosition) {
                $this->pollRemoteStream($redis, $connection, $uuid, $currentPosition);
            });
            return;
        }
        $streamName = $remoteRows[0];
        $remoteRows = $remoteRows[1];
        foreach ($remoteRows as $row) {
            $position = $row[0];
            $properties = $row[1];
            $redis->execute('XADD', $streamName, 'MAXLEN', '~', 1_000_000, $position, ...$properties);
            $currentPosition = $position;
        }
        EventLoop::queue(function () use ($redis, $connection, $uuid, $currentPosition) {
            $this->pollRemoteStream($redis, $connection, $uuid, $currentPosition);
        });
    }

    public function deactivateConnection(string $hexUuid): void
    {
        unset($this->gotPeers[$hexUuid]);
        $this->logger->info('inventory lost ' . $hexUuid);
        // notify?
    }
}
