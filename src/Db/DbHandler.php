<?php

namespace IMEdge\InventoryFeature\Db;

use Amp\TimeoutException;
use Evenement\EventEmitterTrait;
use Exception;
use IMEdge\Async\RetryingFuture;
use IMEdge\DbMigration\Migrations;
use IMEdge\InventoryFeature\State\DaemonProcessDetails;
use IMEdge\PDO\PDO;
use IMEdge\SimpleDaemon\Process;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Revolt\EventLoop;
use RuntimeException;

class DbHandler
{
    use EventEmitterTrait;

    public const ON_CONNECTED = 'connected';
    public const ON_CONNECTING = 'connecting';
    public const ON_LOCKED_BY_OTHER_INSTANCE = 'locked by other instance';
    public const ON_NO_SCHEMA = 'no schema';
    public const ON_SCHEMA_CHANGE = 'schemaChange';
    protected const TABLE_NAME = 'daemon_info';

    protected ?PDO $db = null;

    /** @var DbBasedComponent[] */
    protected array $registeredComponents = [];
    protected ?RetryingFuture $pendingReconnection = null;
    protected ?string $refreshTimer = null;
    protected ?string $schemaCheckTimer = null;
    protected ?int $startupSchemaVersion = null;
    protected DaemonProcessDetails $details;

    public function __construct(
        protected readonly string $dsn,
        protected readonly string $username,
        protected readonly string $password,
        protected readonly ?LoggerInterface $logger = null,
    ) {
        // TODO: support for DB config changing at runtime
        $this->details = new DaemonProcessDetails(Uuid::uuid4()); // TODO: move to parent, ask systemd
    }

    public function register(DbBasedComponent $component): void
    {
        $this->registeredComponents[] = $component;
        if ($this->db !== null) {
            $component->initDb($this->db);
        }
    }

    public function run(): void
    {
        $this->connect();
        $this->refreshTimer = EventLoop::repeat(3, $this->refreshMyState(...));
        $this->schemaCheckTimer = EventLoop::repeat(15, $this->triggerDbSchemaCheck(...));
        EventLoop::defer($this->triggerDbSchemaCheck(...));
    }

    protected function establishConnection(/* $config */): void
    {
        if ($this->db !== null) {
            throw new RuntimeException('Trying to establish a connection while being connected');
        }
        if ($this->pendingReconnection) {
            $this->pendingReconnection->cancel(
                new TimeoutException('Interrupting pending connect, launching a new one')
            );
        }

        $this->pendingReconnection = new RetryingFuture(
            $this->reallyConnect(...),
            'Reconnection',
            10,
            0.2,
            10,
            $this->logger
        );
        $this->emitStatus(self::ON_CONNECTING);
        $this->pendingReconnection->awaitSuccess();
        $this->pendingReconnection = null;
        $this->onConnected();
    }

    protected function reallyConnect(): void
    {
        $db = new PDO($this->dsn, $this->username, $this->password, $this->options ?? [] + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        $migrations = $this->getMigrations($db);
        $this->checkDbSchema($db);
        if ($this->hasAnyOtherActiveInstance($db)) {
            $this->emitStatus(self::ON_LOCKED_BY_OTHER_INSTANCE, 'error');
            throw new RuntimeException('DB is locked by a running daemon instance');
        }
        $this->wipeOrphanedInstances($db);
        $this->startupSchemaVersion = $migrations->getLastMigrationNumber();
        $this->details->set('schema_version', $this->startupSchemaVersion);
        EventLoop::defer($this->refreshMyState(...));

        $this->db = $db;
    }

    /**
     * Call checkDbSchema w/o parameter
     *
     * Problem: EventLoop::defer() & co pass the callback id, and would therefore
     * trigger an invalid type exception with the optional PDO parameter
     */
    protected function triggerDbSchemaCheck(): void
    {
        $this->checkDbSchema();
    }

    protected function checkDbSchema(?PDO $db = null): void
    {
        $db ??= $this->db;
        if ($db === null) {
            return;
        }
        $migrations = $this->getMigrations($db);

        if (! $migrations->hasSchema()) {
            if ($migrations->hasAnyTable()) {
                $this->emitStatus(self::ON_NO_SCHEMA, 'error');
                throw new RuntimeException('DB has no IMEdge schema, but other tables');
            }
            $this->logger->warning('DB has no IMEdge schema, creating now');
            $migrations->createSchema();
            $this->startupSchemaVersion = $migrations->getLastMigrationNumber();
            $this->logger->notice('IMEdge schema has been created');
            return;
        }
        if ($migrations->hasPendingMigrations()) {
            $this->logger->warning('Schema is outdated, applying migrations');
            $count = $migrations->countPendingMigrations();
            try {
                $migrations->applyPendingMigrations();
            } catch (\Throwable $e) {
                $this->logger->error('Applying migrations failed: ' . $e->getMessage());
                return;
            }
            if ($count === 1) {
                $this->logger->notice('A pending DB migration has been applied'); // , restarting the process
            } else {
                $this->logger->notice("$count pending DB migrations have been applied"); // , restarting the DB process
            }
            // EventLoop::defer(Process::restart(...));
        }
        if ($this->schemaIsOutdated($db)) {
            $this->emit(self::ON_SCHEMA_CHANGE, [
                $this->getStartupSchemaVersion(),
                $this->getDbSchemaVersion($db)
            ]);
        }
    }

    protected function getMigrations(PDO $db): Migrations
    {
        return new Migrations($db, dirname(__DIR__, 2) . '/schema', 'inventory');
    }

    protected function schemaIsOutdated(PDO $db): bool
    {
        return $this->getStartupSchemaVersion() < $this->getDbSchemaVersion($db);
    }

    protected function getStartupSchemaVersion(): int
    {
        return $this->startupSchemaVersion ?? 0;
    }

    protected function getDbSchemaVersion(?PDO $db = null): int
    {
        $db = $db ?? $this->db;
        if ($db === null) {
            throw new RuntimeException(
                'Cannot determine DB schema version without an established DB connection'
            );
        }

        return $this->getMigrations($db)->getLastMigrationNumber();
    }

    protected function onConnected(): void
    {
        $this->emitStatus(self::ON_CONNECTED);
        $this->logger->notice('Connected to the database');
        foreach ($this->registeredComponents as $component) {
            $component->initDb($this->db);
        }
    }

    protected function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    public function connect(): void
    {
        if ($this->db === null) {
            // if $this->dbConfig
            $this->establishConnection();
        }
    }

    protected function stopRegisteredComponents(): void
    {
        foreach ($this->registeredComponents as $component) {
            $component->stopDb();
        }
    }

    public function disconnect(): void
    {
        if (! $this->db) {
            return;
        }

        EventLoop::cancel($this->refreshTimer);
        EventLoop::cancel($this->schemaCheckTimer);

        $this->writeStoppedStateToDb();
        $this->db = null;
    }

    protected function emitStatus($message, $level = 'info')
    {
        $this->emit('state', [$message, $level]);
    }

    protected function hasAnyOtherActiveInstance(PDO $db): bool
    {
        return false;
        // return (int) fetchOne > 0: SELECT COUNT(*) FROM daemon_info/self::TABLE_NAME WHERE ts_stopped IS NULL;
    }

    protected function wipeOrphanedInstances(PDO $db): void
    {
        $db->delete(self::TABLE_NAME, 'ts_stopped IS NOT NULL');
        $db->delete(self::TABLE_NAME, ['instance_uuid' => $this->details->instanceUuid]);
        $count = $db->delete(
            self::TABLE_NAME,
            'ts_stopped IS NULL AND ts_last_update < ' . (
                TimeUtil::timestampWithMilliseconds() - (60 * 1000)
            )
        );
        if ($count > 1) {
            $this->logger->warning("Removed $count orphaned daemon instance(s) from DB");
        }
    }

    protected function refreshMyState(): void
    {
        if ($this->db === null || $this->pendingReconnection) {
            return;
        }
        try {
            $updated = $this->db->update(
                self::TABLE_NAME,
                $this->details->getPropertiesForUpdate(),
                ['instance_uuid' => $this->details->instanceUuid->getBytes()]
            );

            if (! $updated) {
                $this->db->insert(
                    self::TABLE_NAME,
                    $this->details->getPropertiesForInsert()
                );
            }
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            $this->reconnect();
        }
    }

    protected function writeStoppedStateToDb(): void
    {
        try {
            if (! $this->db) {
                return;
            }
            $this->db->update(
                self::TABLE_NAME,
                ['ts_stopped' => TimeUtil::timestampWithMilliseconds()],
                ['instance_uuid' => $this->details->instanceUuid->getBytes()]
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to update daemon info (setting ts_stopped): ' . $e->getMessage());
        }
    }
}
