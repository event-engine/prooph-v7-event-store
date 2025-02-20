<?php
/**
 * This file is part of the proophsoftware/event-machine.
 * (c) 2017-2019 prooph software GmbH <contact@prooph.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace EventEngine\Prooph\V7\EventStore\Projecting\InMemory;

use EventEngine\Persistence\InMemoryConnection;
use EventEngine\Prooph\V7\EventStore\InMemoryEventStore;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\EventStoreDecorator;
use Prooph\EventStore\Exception;
use Prooph\EventStore\Projection\ProjectionManager;
use Prooph\EventStore\Projection\ProjectionStatus;
use Prooph\EventStore\Projection\Projector;
use Prooph\EventStore\Projection\Query;
use Prooph\EventStore\Projection\ReadModel;
use Prooph\EventStore\Projection\ReadModelProjector;

final class InMemoryProjectionManager implements ProjectionManager
{
    /**
     * @var EventStore
     */
    private $eventStore;

    /**
     * @var InMemoryConnection
     */
    private $inMemoryConnection;

    /**
     * @var array
     *
     * key = projector name
     * value = projector instance
     */
    private $projectors = [];

    public function __construct(EventStore $eventStore, InMemoryConnection $inMemoryConnection)
    {
        $this->eventStore = $eventStore;
        $this->inMemoryConnection = $inMemoryConnection;

        while ($eventStore instanceof EventStoreDecorator) {
            $eventStore = $eventStore->getInnerEventStore();
        }

        if (! $eventStore instanceof InMemoryEventStore) {
            throw new Exception\InvalidArgumentException('Unknown event store instance given');
        }
    }

    public function createQuery(?array $options = null): Query
    {
        return new InMemoryEventStoreQuery(
            $this->eventStore,
            $this->inMemoryConnection,
            $options[Query::OPTION_PCNTL_DISPATCH] ?? Query::DEFAULT_PCNTL_DISPATCH
        );
    }

    public function createProjection(
        string $name,
        ?array $options = null
    ): Projector {
        $projector = new InMemoryEventStoreProjector(
            $this->eventStore,
            $this->inMemoryConnection,
            $name,
            $options[Projector::OPTION_CACHE_SIZE] ?? Projector::DEFAULT_CACHE_SIZE,
            $options[Projector::OPTION_SLEEP] ?? Projector::DEFAULT_SLEEP,
            $options[Projector::OPTION_PCNTL_DISPATCH] ?? Projector::DEFAULT_PCNTL_DISPATCH
        );

        if (! isset($this->projectors[$name])) {
            $this->projectors[$name] = $projector;
        }

        return $projector;
    }

    public function createReadModelProjection(
        string $name,
        ReadModel $readModel,
        ?array $options = null
    ): ReadModelProjector {
        $projector = new InMemoryEventStoreReadModelProjector(
            $this->eventStore,
            $this->inMemoryConnection,
            $name,
            $readModel,
            $options[ReadModelProjector::OPTION_CACHE_SIZE] ?? ReadModelProjector::DEFAULT_CACHE_SIZE,
            $options[ReadModelProjector::OPTION_PERSIST_BLOCK_SIZE] ?? ReadModelProjector::DEFAULT_PERSIST_BLOCK_SIZE,
            $options[ReadModelProjector::OPTION_SLEEP] ?? ReadModelProjector::DEFAULT_SLEEP,
            $options[ReadModelProjector::OPTION_PCNTL_DISPATCH] ?? ReadModelProjector::DEFAULT_PCNTL_DISPATCH
        );

        if (! isset($this->projectors[$name])) {
            $this->projectors[$name] = $projector;
        }

        return $projector;
    }

    public function deleteProjection(string $name, bool $deleteEmittedEvents): void
    {
        throw new Exception\RuntimeException('Deleting a projection is not supported in ' . \get_class($this));
    }

    public function resetProjection(string $name): void
    {
        throw new Exception\RuntimeException('Resetting a projection is not supported in ' . \get_class($this));
    }

    public function stopProjection(string $name): void
    {
        throw new Exception\RuntimeException('Stopping a projection is not supported in ' . \get_class($this));
    }

    public function fetchProjectionNames(?string $filter, int $limit = 20, int $offset = 0): array
    {
        if (1 > $limit) {
            throw new Exception\OutOfRangeException(
                'Invalid limit "'.$limit.'" given. Must be greater than 0.'
            );
        }

        if (0 > $offset) {
            throw new Exception\OutOfRangeException(
                'Invalid offset "'.$offset.'" given. Must be greater or equal than 0.'
            );
        }

        if (null === $filter) {
            $result = \array_keys($this->projectors);
            \sort($result, \SORT_STRING);

            return \array_slice($result, $offset, $limit);
        }

        if (isset($this->projectors[$filter])) {
            return [$filter];
        }

        return [];
    }

    public function fetchProjectionNamesRegex(string $regex, int $limit = 20, int $offset = 0): array
    {
        if (1 > $limit) {
            throw new Exception\OutOfRangeException(
                'Invalid limit "'.$limit.'" given. Must be greater than 0.'
            );
        }

        if (0 > $offset) {
            throw new Exception\OutOfRangeException(
                'Invalid offset "'.$offset.'" given. Must be greater or equal than 0.'
            );
        }

        \set_error_handler(function ($errorNo, $errorMsg): void {
            throw new Exception\RuntimeException($errorMsg);
        });

        try {
            $result = \preg_grep("/$regex/", \array_keys($this->projectors));
            \sort($result, \SORT_STRING);

            return \array_slice($result, $offset, $limit);
        } catch (Exception\RuntimeException $e) {
            throw new Exception\InvalidArgumentException('Invalid regex pattern given', 0, $e);
        } finally {
            \restore_error_handler();
        }
    }

    public function fetchProjectionStatus(string $name): ProjectionStatus
    {
        if (! isset($this->projectors[$name])) {
            throw Exception\ProjectionNotFound::withName($name);
        }

        $projector = $this->projectors[$name];

        $ref = new \ReflectionProperty(\get_class($projector), 'status');
        $ref->setAccessible(true);

        return $ref->getValue($projector);
    }

    public function fetchProjectionStreamPositions(string $name): array
    {
        if (! isset($this->projectors[$name])) {
            throw Exception\ProjectionNotFound::withName($name);
        }

        $projector = $this->projectors[$name];

        $ref = new \ReflectionProperty(\get_class($projector), 'streamPositions');
        $ref->setAccessible(true);
        $value = $ref->getValue($projector);

        return (null === $value) ? [] : $value;
    }

    public function fetchProjectionState(string $name): array
    {
        if (! isset($this->projectors[$name])) {
            throw Exception\ProjectionNotFound::withName($name);
        }

        return $this->projectors[$name]->getState();
    }
}
