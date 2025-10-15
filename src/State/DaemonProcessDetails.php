<?php

namespace IMEdge\InventoryFeature\State;

use IMEdge\InventoryFeature\Db\TimeUtil;
use IMEdge\SimpleDaemon\Process;
use InvalidArgumentException;
use Ramsey\Uuid\UuidInterface;

class DaemonProcessDetails
{
    protected \stdClass $info;

    public function __construct(
        public readonly UuidInterface $instanceUuid
    ) {
        $this->initialize();
    }

    public function getPropertiesForInsert(): array
    {
        return $this->getPropertiesForUpdate() + (array) $this->info;
    }

    public function getPropertiesForUpdate(): array
    {
        return [
            'ts_last_update' => TimeUtil::timestampWithMilliseconds(),
            'ts_stopped'     => null,
        ];
    }

    public function set(string $property, mixed $value): void
    {
        if (\property_exists($this->info, $property)) {
            $this->info->$property = $value;
        } else {
            throw new InvalidArgumentException("Trying to set invalid daemon info property: $property");
        }
    }

    // TODO: move to parent process
    protected function initialize(): void
    {
        if (isset($_SERVER['_'])) {
            $self = $_SERVER['_'];
        } else {
            // Process does a better job, but want the relative path (if such)
            $self = $_SERVER['PHP_SELF'];
        }
        $this->info = (object) [
            'instance_uuid'        => $this->instanceUuid->getBytes(),
            'running_with_systemd' => 'n',
            'ts_started'           => (int) ((float) $_SERVER['REQUEST_TIME_FLOAT'] * 1000),
            'ts_stopped'           => null,
            'pid'                  => \posix_getpid(),
            'fqdn'                 => gethostbyaddr(gethostbyname(gethostname())), // hint: this might block
            'username'             => posix_getpwuid(posix_geteuid())['name'],
            'schema_version'       => null,
            'php_version'          => phpversion(),
            'binary_path'          => $self,
            'binary_realpath'      => Process::getBinaryPath(),
            'php_integer_size'     => PHP_INT_SIZE,
            'php_binary_path'      => PHP_BINARY,
            'php_binary_realpath'  => \realpath(PHP_BINARY), // TODO: useless?
        ];
    }
}
