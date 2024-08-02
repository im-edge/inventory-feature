<?php

namespace IMEdge\InventoryFeature;

use Amp\Future;
use gipfl\Json\JsonException;
use gipfl\Json\JsonString;
use IMEdge\Inventory\CentralInventory;
use IMEdge\Inventory\InventoryActionType;
use IMEdge\InventoryFeature\Db\DbConnection;
use IMEdge\InventoryFeature\Db\DbQueryHelper;
use IMEdge\Inventory\NodeIdentifier;
use IMEdge\Node\Feature;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use React\Promise\Deferred;
use Revolt\EventLoop;

use function Amp\async;
use function React\Async\await;

class InventoryRunner implements CentralInventory
{
    protected bool $logActivities = true;

    public function __construct(
        protected readonly Feature $feature,
        protected readonly DbConnection $db,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function run(): void
    {
        EventLoop::delay(1.5, $this->shipLocalSnmpCredentials(...));
    }

    public function shipLocalSnmpCredentials(): void
    {
        // TODO: redesign this
        if ($api = $this->getLocalSnmpApi()) {
            $this->logger->notice('InventoryRunner found SNMP when starting up, loading credentials');
            $localCredentials = CredentialLoader::fetchAllForDataNode($this->feature->nodeIdentifier->uuid, $this->db);
            try {
                $api->setCredentials($localCredentials);
            } catch (\Exception $e) {
                $this->logger->error('Sending SNMP credentials failed (InventoryRunner): ' . $e->getMessage());
            }
        } else {
            $this->logger->notice('InventoryRunner found no SNMP when starting up');
        }
    }

    protected function getLocalSnmpApi(): ?object
    {
        foreach ($this->feature->getRegisteredRpcApis() as $api) {
            // TODO: Check reflection -> ApiNamespace
            if (method_exists($api, 'setCredentialsRequest')) {
                return $api;
            }
        }

        return null;
    }

    /**
     * @deprecated
     */
    public function setSyncError(UuidInterface $nodeUuid, string $table, \Throwable $e): void
    {
        $db = new DbQueryHelper($this->db->getPool(), $this->logger);
        $db->update(
            $table,
            ['current_error' => $e->getMessage()],
            ['datanode_uuid' => $nodeUuid->getBytes()]
        );
    }

    /**
     * @deprecated
     */
    public function shipBulkActions(array $actions): void
    {
        $deferred = new Deferred();
        if (empty($actions)) {
            EventLoop::queue(function () use ($deferred) {
                $deferred->resolve(null);
            });
            await($deferred->promise());
            return;
        }
        $start = microtime(true);
        $transaction = $this->db->transaction();
        $db = new DbQueryHelper($transaction, $this->logger);
        try {
            $queries = [];
            $futures = [];
            $formerTable = null;
            foreach ($actions as $action) {
                $futures[] = async(function () use (&$queries, $action, $db, &$formerTable) {
                    $table = $action->tableName;
                    if ($formerTable && ($formerTable !== $table)) {

                    }
                    $values = $action->getAllDbValues();
                    $keyProperties = $action->getDbKeyProperties();

                    // hard-coded fixes for old structures
                    if ($table === 'rrd_archive') {
                        if (isset($values['uuid'])) {
                            $values['rrd_archive_set_uuid'] = $values['uuid'];
                        }
                        if (isset($keyProperties['uuid'])) {
                            $keyProperties['rrd_archive_set_uuid'] = $keyProperties['uuid'];
                        }
                    }
                    if ($table === 'rrd_file') {
                        unset($values['tags']);
                        if (isset($values['filename'])) {
                            $values['filename'] = preg_replace(
                                '#/rrd/data/lab1/rrdcached/data/#',
                                '',
                                $values['filename']
                            );
                        }
                    }
                    // end of fixes

                    try {
                        $queries[] = match ($action->action) {
                            InventoryActionType::CREATE => fn () => $db->insert($table, $values, $this->logger),
                            InventoryActionType::UPDATE => fn () => $db->update($table, $action->getDbValuesForUpdate(), $keyProperties),
                            InventoryActionType::DELETE => fn () => $db->delete($table, $keyProperties),
                        };
                        if ($this->logActivities) {
                            $queries[] = $db->insert('datanode_table_action_history', [
                                'datanode_uuid' => $action->sourceNode->getBytes(),
                                'table_name' => $table,
                                'stream_position' => $action->streamPosition,
                                'action' => $action->action->value,
                                'key_properties' => JsonString::encode($action->keyProperties),
                                'sent_values' => JsonString::encode($action->values),
                            ]);
                        }
                    } catch (JsonException $e) {
                        $this->logger->error(
                            "shipBulkActions: " . $e->getMessage() . ', encoding failed for '. print_r($action, 1)
                        );
                    } catch (\Throwable $e) {
                        if (str_contains($e->getMessage(), 'Duplicate entry')) {
                            $this->logger->error('Ignoring duplicate key error: ' . $e->getMessage());
                        } else {
                            $this->logger->error('WTF: ' . $e->getMessage());
                            throw $e;
                        }
                    }
                });
                // TODO: $this->logAction?
            }
            $futures[] = async(function () use ($db, $action) {
                $db->update('datanode_table_sync', [
                    'current_position' => $action->streamPosition,
                    'current_error' => null,
                ], [
                    'datanode_uuid' => $action->sourceNode->getBytes(),
                    'table_name'    => $action->tableName, // Hint: works here, but... not so nice
                ]);
            });
            Future\awaitAll($futures);
            $this->logger->notice(sprintf(
                'Prepared %d queries in %.02fms',
                count($queries),
                (microtime(true) - $start) * 1000
            ));
            $commit = async(function () use ($transaction) {
                $transaction->commit();
            })->catch(function (\Throwable $e) {
                $this->logger->error('COMMIT failed: ' . $e->getMessage());
            })->finally(function () use ($deferred) {
                $deferred->resolve(null);
            });
            Future\await([$commit]);
            $this->logger->notice(sprintf(
                'COMMITTED %d queries in %.02fms',
                count($queries),
                (microtime(true) - $start) * 1000
            ));
            $deferred->resolve([$action->streamPosition]);
        } catch (\Throwable $e) {
            $this->logger->error('Transaction failed ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
            $transaction->rollback();
            try {
                $this->setSyncError($action->sourceNode, $action->tableName, $e);
            } catch (\Throwable $e) {
                $this->logger->notice('Unable to write error information to DB: ' . $e->getMessage());
            }
        }

        await($deferred->promise());
    }

    public function getCredentials(): array
    {
        // TODO: Implement getCredentials() method.
        return [];
    }

    public function loadTableSyncPositions(NodeIdentifier $nodeIdentifier): array
    {
        return $this->db->fetchPairs(
            'SELECT table_name, current_position FROM datanode_table_sync WHERE datanode_uuid = ?',
            [$nodeIdentifier->uuid->getBytes()]
        );
    }
}
