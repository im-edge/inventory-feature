<?php

namespace IMEdge\InventoryFeature;

use IMEdge\Async\Retry;
use IMEdge\Inventory\InventoryAction;
use IMEdge\Inventory\InventoryActionType;
use IMEdge\InventoryFeature\Db\PdoQueryHelper;
use IMEdge\Json\JsonString;
use IMEdge\RedisTables\RedisTables;
use IMEdge\RedisUtils\RedisResult;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Revolt\EventLoop;
use RuntimeException;
use SensitiveParameter;

class DbStreamWriter
{
    protected array $streamPositions = [];
    protected ?PDO $db = null;
    protected ?PdoQueryHelper $queryHelper = null;

    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly UuidInterface $dataNodeUuid,
        public string $dsn,
        public ?string $username = null,
        #[SensitiveParameter]
        public ?string $password = null,
        public ?array $options = null,
    ) {
        $this->db();
        EventLoop::repeat(12, $this->refreshStreamPositions(...));
    }

    protected function refreshStreamPositions(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        $result = [];
        foreach ($db->query('SELECT uuid, db_stream_position FROM datanode WHERE db_stream_error IS NULL ORDER BY RAND();') as $row) {
            $result[RedisTables::STREAM_NAME_PREFIX . Uuid::fromBytes($row[0])->toString()] = $row[1];
        }

        $this->streamPositions = $result;
    }

    public function getCurrentStreamPositions(): ?array
    {
        if ($this->db === null) {
            return null;
        }

        return $this->streamPositions;
    }

    protected function extractNodeUuidFromStreamName(string $string): string
    {
        if (str_starts_with($string, RedisTables::STREAM_NAME_PREFIX)) {
            return substr($string, strlen(RedisTables::STREAM_NAME_PREFIX));
        } else {
            throw new RuntimeException(sprintf('"%s" is not a valid stream result table', $string));
        }
    }

    protected function processResultRow($row, array &$streamPositions, int &$cntQueries): void
    {
        $streamName = $row[0];
        if (!isset($streamPositions[$streamName])) {
            // Skip failing streams early. Happens when the outer processRedisStreamResults
            // is still running, but we already failed.
            return;
        }
        $nodeUuid = static::extractNodeUuidFromStreamName($streamName);
        foreach ($row[1] as $entry) {
            $streamPosition = $entry[0];
            $rawData = RedisResult::toHash($entry[1]);
            $values = isset($rawData->value) ? JsonString::decode($rawData->value) : null;
            $keyProperties = JsonString::decode($rawData->keyProperties);
            if ($values) {
                self::fixErroneousOidSerialization($values);
            }
            $action = new InventoryAction(
                $this->dataNodeUuid,
                $rawData->table,
                $entry[0], // TODO: probably not used
                InventoryActionType::from($rawData->action),
                $rawData->key,
                $rawData->checksum ?? null,
                $keyProperties,
                (array) $values,
            );
            try {
                if (isset($streamPositions[$streamName])) { // Skipping failing streams
                    $this->storeInventoryAction($action);
                    $cntQueries++;
                    $streamPositions[$streamName] = $streamPosition;
                }
            } catch (PDOException $e) {
                $this->logger->debug($e->getMessage());
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    $this->logger->error(sprintf(
                        'Ignoring duplicate key error for %s: %s',
                         $this->queryHelper->lastSql,
                        $e->getMessage()
                    ));
                } else {
                    $this->logger->error('Failed query: ' . $this->queryHelper->lastSql);
                    $this->setSyncError($nodeUuid, $streamPositions[$streamName], $e);
                    unset($streamPositions[$streamName]);
                    return; // exit from the current (inner) loop
                }
            } catch (\Throwable $e) {
                $this->logger->error('DbStreamWriter failed badly: ' . $e->getMessage());
                $this->setSyncError($nodeUuid, $streamPositions[$streamName], $e);
                unset($streamPositions[$streamName]);
                return; // exit from the current (inner) loop
            }
        }
    }

    public function processRedisStreamResults(array $results): void
    {
        if (empty($results)) {
            return;
        }
        $db = $this->db() ?? throw new \Exception('DB is not ready');
        $start = microtime(true);
        $db->beginTransaction();
        $streamPositions = $this->streamPositions;
        $cntQueries = 0;

        foreach ($results as $row) {
            $this->processResultRow($row, $streamPositions, $cntQueries);
        }

        try {
            foreach ($streamPositions as $nodeUuid => $streamPosition) {
                $nodeUuid = self::extractNodeUuidFromStreamName($nodeUuid);
                $this->queryHelper->update('datanode', [
                    'db_stream_position' => $streamPosition,
                    'db_stream_error' => null,
                ], [
                    'uuid' => Uuid::fromString($nodeUuid)->getBytes(),
                ]);
            }

            $this->logger->notice(sprintf(
                'Prepared %d queries in %.02fms',
                $cntQueries,
                (microtime(true) - $start) * 1000
            ));
            $db->commit();
            $this->streamPositions = $streamPositions;
            $this->logger->notice(sprintf(
                'COMMITTED %d queries in %.02fms',
                $cntQueries,
                (microtime(true) - $start) * 1000
            ));
        } catch (PDOException $e) {
            $this->logger->error('COMMIT failed: ' . $e->getMessage());
            try {
                $db->rollBack();
            } catch (PDOException $e) {
                $this->logger->error('ROLLBACK failed: ' . $e->getMessage());
            }
            try {
                $db->query('SELECT 1 FROM DUAL');
            } catch (PDOException) {
                $this->db = null;
                $this->queryHelper = null;
            }
        }
    }

    protected function storeInventoryAction(InventoryAction $action): void
    {
        $table = $action->tableName;
        $values = $action->getAllDbValues();
        $keyProperties = $action->getDbKeyProperties();
        if ($action->action === InventoryActionType::DELETE) {
            if ($keyProperties === ['uuid' => null]) {
                // Workaround for RRD file deletion for now
                $keyProperties = ['uuid' => Uuid::fromString($action->key)->getBytes()];
            }
        }
        $result = match ($action->action) {
            InventoryActionType::CREATE => $this->queryHelper->insert(
                $table,
                $values
            ),
            InventoryActionType::UPDATE => $this->queryHelper->update(
                $table,
                $action->getDbValuesForUpdate(),
                $keyProperties
            ),
            InventoryActionType::DELETE => $this->queryHelper->delete(
                $table,
                $keyProperties
            ),
        };
    }

    public function setSyncError(string $nodeUuid, string $lastSuccessfulPosition, \Throwable $e): void
    {
        $this->logger->error(sprintf('DB Stream sync error for %s: %s', $nodeUuid, $e->getMessage()));
        $this->queryHelper->update('datanode', [
            'db_stream_position' => $lastSuccessfulPosition,
            'db_stream_error'    => $e->getMessage() . "\n" . $this->queryHelper->lastSql,
        ], ['uuid'      => Uuid::fromString($nodeUuid)->getBytes()], $this->logger);
    }

    protected function db(): ?PDO
    {
        if ($this->db === null) {
            Retry::forever(function () {
                $this->db = new PDO($this->dsn, $this->username, $this->password, $this->options ?? [] + [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $this->queryHelper = new PdoQueryHelper($this->db, $this->logger);
                $this->refreshStreamPositions();
                $this->logger->notice('DB connection has been established');
            }, 'DB connection', 15, 1, 30, $this->logger);
        }

        return $this->db;
    }

    protected static function fixErroneousOidSerialization(&$values): void
    {
        foreach ($values as &$value) { // Fix for erroneous serialization
            if (is_object($value) && isset($value->oid)) {
                $value = $value->oid;
            }
        }
    }
}
