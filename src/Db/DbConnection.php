<?php

namespace IMEdge\InventoryFeature\Db;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\MysqlTransaction;

/**
 * TODO: should we deprecate this, and replace it with requests to our
 *       synchronous child process?
 */
class DbConnection
{
    protected MysqlConnectionPool $pool;

    public function __construct(string $dsn, string $username, string $password)
    {
        $config = MysqlConfig::fromString($dsn)
            ->withUser($username)
            ->withPassword($password)
            ->withCharset('utf8mb4', 'utf8mb4_unicode_ci'); // utf8mb4_bin
        $this->pool = new MysqlConnectionPool($config);
    }

    public function transaction(): MysqlTransaction
    {
        // TODO: pass a specific transaction isolation level?
        return $this->pool->beginTransaction();
    }

    public function getPool(): MysqlConnectionPool
    {
        return $this->pool;
    }

    protected static function quoteColumnName(string $name): string
    {
        return $name;
    }

    public function fetchAll($sql, $params = []): array
    {
        $statement = $this->pool->prepare($sql);
        $result = [];
        foreach ($statement->execute($params) as $row) {
            $result[] = $row;
        }

        return $result;
    }

    public function fetchPairs($sql, $params = []): array
    {
        $statement = $this->pool->prepare($sql);
        $result = [];
        foreach ($statement->execute($params) as $row) {
            [$key, $value] = array_values($row);
            $result[$key] = $value;
        }

        return $result;
    }
}
