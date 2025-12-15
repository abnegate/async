<?php

namespace Utopia\Async\Parallel\Adapter\Amp;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task as AMPHPTask;
use Amp\Sync\Channel;

/**
 * Task implementation for Amp parallel worker execution.
 *
 * @implements AMPHPTask<mixed, never, never>
 * @package Utopia\Async\Parallel\Adapter
 */
class Task implements AMPHPTask
{
    /**
     * @param string $serializedTask Serialized closure
     * @param array<mixed> $args Arguments to pass to the task
     */
    public function __construct(
        private string $serializedTask,
        private array $args
    ) {
    }

    /**
     * Execute the task in the worker.
     *
     * @param Channel<never, never> $channel Communication channel (unused)
     * @param Cancellation $cancellation Cancellation token
     * @return mixed The task result
     */
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $task = \Opis\Closure\unserialize($this->serializedTask);

        if (!\is_callable($task)) {
            throw new \RuntimeException('Deserialized task is not callable');
        }

        return empty($this->args) ? $task() : $task(...$this->args);
    }
}
