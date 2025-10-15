<?php

namespace IMEdge\InventoryFeature\Db;

use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;

/**
 * @deprecated
 */
class PdoQueryHelper
{
    /**
     * @var PDOStatement[]
     */
    protected array $prepared = [];
    public ?string $lastSql = null;

    public function __construct(
        protected readonly PDO $pdo,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function insert(string $table, array $values): false|int
    {
        $columns = implode(', ', array_map(self::quoteColumnName(...), array_keys($values)));
        $placeHolders = implode(', ', array_fill(0, count($values), '?'));
        $sql = "INSERT INTO $table ($columns) VALUES ($placeHolders)";
        $this->lastSql = $sql;
        // echo "$sql\n";
        // $this->logger?->debug($sql);
        $statement = $this->prepare($sql);
        return $statement->execute(array_values($values));
    }

    public function update(string $table, array $values, array $keyParams): bool
    {
        [$set, $params] = self::makeSet($values);
        [$where, $whereParams] = self::makeWhere($keyParams);
        // $this->logger->notice("UPDATE $table SET " . print_r($set, 1) . " $where " . print_r($whereParams));
        $sql = "UPDATE $table SET $set $where";
        $this->lastSql = $sql;
        // echo "$sql\n";
        // $this->logger?->debug($sql);
        $statement = $this->prepare($sql);
        return $statement->execute(array_merge($params, $whereParams));
    }

    public function delete(string $table, array $keyParams): bool
    {
        [$where, $params] = self::makeWhere($keyParams);
        $sql = "DELETE FROM $table $where";
        // echo "$sql\n";
        $this->lastSql = $sql;
        // $this->logger?->debug($sql);
        $statement = $this->prepare($sql);
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
        // TODO: implement
        return $name;
    }

    /**
     * @deprecated Not sure, whether this is required
     */
    public function fetchAll($sql): array
    {
        // TODO: w/o prepare?
        $result = [];
        foreach ($this->prepare($sql) as $row) {
            $result[] = $row;
        }

        return $result;
    }

    protected function prepare($query): PDOStatement
    {
        return $this->prepared[$query] ?? $this->pdo->prepare($query);
    }
}
