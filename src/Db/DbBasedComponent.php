<?php

namespace IMEdge\InventoryFeature\Db;

use IMEdge\PDO\PDO;

interface DbBasedComponent
{
    public function initDb(PDO $db): void;

    public function stopDb(): void;
}
