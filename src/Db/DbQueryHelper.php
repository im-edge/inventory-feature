<?php

namespace IMEdge\InventoryFeature\Db;

use Amp\Mysql\MysqlExecutor;
use Amp\Mysql\MysqlResult;
use Psr\Log\LoggerInterface;

class DbQueryHelper
{
    public function __construct(
        protected readonly MysqlExecutor $executor,
        protected readonly LoggerInterface $logger,
    ) {}

    public function insert(string $table, array $values, ?LoggerInterface $logger = null): MysqlResult
    {
        $columns = implode(', ', array_map(self::quoteColumnName(...), array_keys($values)));
        $placeHolders = implode(', ', array_fill(0, count($values), '?'));
        // $this->logger->notice("INSERT INTO $table ($columns) VALUES ($placeHolders)");
        if ($logger) {
            $logger->notice("INSERT INTO $table ($columns) VALUES ($placeHolders)");
        }
        $statement = $this->executor->prepare("INSERT INTO $table ($columns) VALUES ($placeHolders)");
        return $statement->execute(array_values($values));
    }

    public function update(string $table, array $values, array $keyParams): MysqlResult
    {
        [$set, $params] = self::makeSet($values);
        [$where, $whereParams] = self::makeWhere($keyParams);
        // $this->logger->notice("UPDATE $table SET " . print_r($set, 1) . " $where " . print_r($whereParams));
        $statement = $this->executor->prepare("UPDATE $table SET $set $where");
        return $statement->execute(array_merge($params, $whereParams));
    }

    public function delete(string $table, array $keyParams): MysqlResult
    {
        [$where, $params] = self::makeWhere($keyParams);
        $statement = $this->executor->prepare("DELETE FROM $table $where");
        return $statement->execute($params);
    }

    protected static function makeSet(array $values): array
    {
        $columns = [];
        $params = [];
        foreach ($values as $key => $value) {
            $columns[] = sprintf('%s = ?', self::quoteColumnName($key));
            $params[] = $value;
        }
        $set = implode(', ', $columns);

        return [$set, $params];
    }

    protected static function makeWhere(array $keyParams): array
    {
        $where = 'WHERE ';
        $params = [];
        foreach ($keyParams as $key => $value) {
            if ($where !== 'WHERE ') {
                $where .= ' AND ';
            }

            if ($value === null) {
                $where .= "$key IS NULL";
            } else {
                $where .= "$key = ?";
                $params[] = $value;
            }
        }

        return [$where, $params];
    }

    protected static function quoteColumnName(string $name): string
    {
        return $name;
    }

    public function fetchAll($sql): array
    {
        $statement = $this->executor->prepare($sql);
        $result = [];
        foreach ($statement->execute() as $row) {
            $result[] = $row;
        }

        return $result;
    }
}
