<?php

namespace IMEdge\InventoryFeature\Db;

class TimeUtil
{
    public static function timestampWithMilliseconds(): int
    {
        $mTime = explode(' ', microtime());

        return (int) round((int) $mTime[0] * 1000) + (int) $mTime[1] * 1000;
    }
}
