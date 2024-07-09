<?php

namespace IMEdge\InventoryFeature;

use Amp\Redis\Connection\RedisConnectionException;
use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisClient;
use Exception;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Revolt\EventLoop;

use function Amp\Redis\createRedisClient;

final class DbStreamReader
{
    protected const NAME = 'IMEdge/DbStreamReader';
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
        $this->redis = createRedisClient('unix://' . $redisSocket);
    }

    public function start(): void
    {
        Retry::forever(function () {
            $this->redis->execute('CLIENT', 'SETNAME', self::NAME);
            $this->initializeReadParams();
            $this->logger->notice(self::NAME . ': Redis/ValKey is ready');
            $this->readStreams();
        }, 'Redis/ValKey', 10, 1, 30, $this->logger);
    }

    protected function initializeReadParams(): void
    {
        $blockMs = 15000;
        $maxCount = 10000;
        $this->xReadParams = ['XREAD', 'COUNT', (string) $maxCount, 'BLOCK', (string) $blockMs, 'STREAMS'];
    }

    protected function readStreams(): void
    {
        $params = null;
        try {
            $positions = $this->writer->getCurrentStreamPositions();
            if ($positions) {
                $params = array_merge($this->xReadParams, array_keys($positions), array_values($positions));
                // $this->logger->debug(implode(' ', $params));
                $streams = $this->redis->execute(...$params);
                if ($this->redisFailing) {
                    $this->logger->notice('Redis/ValKey reconnected');
                    $this->redisFailing = false;
                }
                if ($streams) {
                    $this->writer->processRedisStreamResults($streams);
                }
                if (! $this->stopping) {
                    EventLoop::queue($this->readStreams(...));
                }
            } else {
                // DB not ready
                if (! $this->stopping) {
                    EventLoop::delay(1, $this->readStreams(...));
                }
            }
        } catch (RedisConnectionException) {
            if (!$this->stopping) {
                $this->start();
            }
        } catch (QueryException $e) {
            // e.g.: LOADING Redis is loading the dataset in memory
            $this->logger->error(
                'Redis/ValKey query failed' . ($params ? ' (' . implode(' ', $params) . ')' : '') . $e->getMessage()
            );
            if (!$this->stopping) {
                $this->start();
            }
        } catch (Exception $e) {
            if ($this->stopping) {
                return;
            }
            if (! $this->redisFailing) {
                $this->redisFailing = true;
                $this->logger->error(sprintf(
                    'Reading next Redis/ValKey stream batch failed with %s, retrying every %ds: %s',
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
