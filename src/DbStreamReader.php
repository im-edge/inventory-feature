<?php

namespace IMEdge\InventoryFeature;

use Amp\Redis\Connection\RedisConnectionException;
use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisClient;
use Exception;
use IMEdge\Async\Retry;
use IMEdge\Node\Redis\ImedgeRedis;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Revolt\EventLoop;
use Throwable;

use function Amp\delay;

final class DbStreamReader
{
    protected const NAME = 'IMEdge/DbStreamReader';
    protected const STORE_APP = 'Redis/ValKey';
    protected const DELAY_ON_ERROR = 15;

    protected ?RedisClient $redis = null;
    protected ?array $xReadParams;
    protected bool $stopping = false;
    protected bool $redisFailing = false;

    public function __construct(
        protected string $redisSocket,
        protected DbStreamWriter $writer,
        protected readonly UuidInterface $dataNodeUuid,
        protected readonly LoggerInterface $logger,
    ) {
        $this->logger->notice('Launching ' . self::NAME);
        $this->redis = ImedgeRedis::client(self::NAME);
    }

    public function start(): void
    {
        $this->stopping = false;
        $this->launch();
    }

    protected function launch(): void
    {
        if ($this->stopping) {
            return;
        }

        $this->initializeReadParams();
        $succeeded = false;
        while (!$succeeded) {
            try {
                $this->redis->ping();
                $succeeded = true;
            } catch (Throwable) {
                delay(0.3);
            }
        }
        $this->logger->notice(sprintf('%s is connected to %s', self::NAME, self::STORE_APP));
        Retry::forever(function () {
            $this->readStreams();
        }, self::STORE_APP, 10, 1, 30, $this->logger);
    }

    protected function initializeReadParams(): void
    {
        $blockMs = 15000;
        $maxCount = 10000;
        $this->xReadParams = ['XREAD', 'COUNT', (string) $maxCount, 'BLOCK', (string) $blockMs, 'STREAMS'];
    }

    protected function readStreams(): void
    {
        if ($this->stopping) {
            return;
        }
        $params = null;
        try {
            $positions = $this->writer->getCurrentStreamPositions();
            if ($positions) {
                $params = array_merge($this->xReadParams, array_keys($positions), array_values($positions));
                // $this->logger->debug(implode(' ', $params));
                $streams = $this->redis->execute(...$params);
                if ($this->redisFailing) {
                    $this->logger->notice(self::STORE_APP . ' reconnected');
                    $this->redisFailing = false;
                }
                if ($streams) {
                    $this->writer->processRedisStreamResults($streams);
                }
                EventLoop::queue($this->readStreams(...));
            } else {
                // DB not ready
                EventLoop::delay(1, $this->readStreams(...));
            }
        } catch (RedisConnectionException) {
            $this->start();
        } catch (QueryException $e) {
            // e.g.: LOADING Redis is loading the dataset in memory
            $this->logger->error(
                self::STORE_APP . ' query failed'
                . ($params ? ' (' . implode(' ', $params) . ')' : '')
                . $e->getMessage()
            );
            $this->start();
        } catch (Exception $e) {
            if (! $this->redisFailing) {
                $this->redisFailing = true;
                $this->logger->error(sprintf(
                    'Reading next %s stream batch failed with %s, retrying every %ds: %s',
                    self::STORE_APP,
                    get_class($e),
                    self::DELAY_ON_ERROR,
                    $e->getMessage()
                ));
            }
            EventLoop::delay(self::DELAY_ON_ERROR, $this->readStreams(...));
        }
    }

    public function stop(): void
    {
        $this->stopping = true;
    }
}
