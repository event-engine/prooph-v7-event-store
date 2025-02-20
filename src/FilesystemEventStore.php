<?php
declare(strict_types=1);

namespace EventEngine\Prooph\V7\EventStore;

use DateTimeImmutable;
use DateTimeZone;
use EventEngine\Persistence\InMemoryConnection;
use EventEngine\Persistence\TransactionalConnection;
use EventEngine\Prooph\V7\EventStore\Exception\FailedToWriteFile;
use Iterator;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\MessageConverter;
use Prooph\Common\Messaging\MessageFactory;
use Prooph\Common\Messaging\NoOpMessageConverter;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\EventStoreDecorator;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use Prooph\EventStore\TransactionalEventStore;

final class FilesystemEventStore implements TransactionalEventStore, EventStoreDecorator
{
    /**
     * @var InMemoryEventStore
     */
    private $inMemoryStore;

    /**
     * @var TransactionalConnection
     */
    private $transactionalConnection;

    /**
     * @var MessageFactory
     */
    private $messageFactory;

    /**
     * @var MessageConverter
     */
    private $messageConverter;

    /**
     * @var string
     */
    private $filename;

    /**
     * @var int
     */
    private $jsonEncodeOptions;

    public function __construct(
        string $filename,
        int $jsonEncodeOptions = 0,
        ?MessageFactory $messageFactory = null,
        ?MessageConverter $messageConverter = null
    )
    {
        $data = [];

        if(!file_exists($filename)) {
            if(!touch($filename)) {
                throw FailedToWriteFile::with($filename, 'Touch file failed');
            }
        } else {
            $data = \json_decode(\file_get_contents($filename), true);
        }

        if(null === $messageFactory) {
            $messageFactory = new FQCNMessageFactory();
        }

        if(null === $messageConverter) {
            $messageConverter = new NoOpMessageConverter();
        }

        $this->messageFactory = $messageFactory;
        $this->messageConverter = $messageConverter;

        $this->transactionalConnection = new InMemoryConnection();

        $events = $data['events'] ?? [];

        foreach ($events as $streamName => $streamEvents) {
            $proophEvents = [];
            foreach ($streamEvents as $event) {
                $event['created_at'] = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s.u',
                    $event['created_at'],
                    new DateTimeZone('UTC')
                );
                $proophEvents[] = $this->messageFactory->createMessageFromArray($event['message_name'], $event);
            }
            $events[$streamName] = $proophEvents;
        }

        $this->transactionalConnection['events'] = $events;
        $this->transactionalConnection['event_streams'] = $data['event_streams'] ?? [];
        $this->transactionalConnection['projections'] = $data['projections'] ?? [];

        $this->inMemoryStore = new InMemoryEventStore($this->transactionalConnection);
        $this->filename = $filename;
        $this->jsonEncodeOptions = $jsonEncodeOptions;

    }

    public function create(Stream $stream): void
    {
        if(!$this->transactionalConnection->inTransaction()) {
            $this->transactional(function () use($stream) {
                $this->inMemoryStore->create($stream);
            });
        } else {
            $this->inMemoryStore->create($stream);
        }
    }

    public function appendTo(StreamName $streamName, Iterator $streamEvents): void
    {
        if(!$this->transactionalConnection->inTransaction()) {
            $this->transactional(function () use($streamName, $streamEvents) {
                $this->inMemoryStore->appendTo($streamName, $streamEvents);
            });
        } else {
            $this->inMemoryStore->appendTo($streamName, $streamEvents);
        }
    }

    public function updateStreamMetadata(StreamName $streamName, array $newMetadata): void
    {
        if(!$this->transactionalConnection->inTransaction()) {
            $this->transactional(function () use($streamName, $newMetadata) {
                $this->inMemoryStore->updateStreamMetadata($streamName, $newMetadata);
            });
        } else {
            $this->inMemoryStore->updateStreamMetadata($streamName, $newMetadata);
        }
    }

    public function delete(StreamName $streamName): void
    {
        if(!$this->transactionalConnection->inTransaction()) {
            $this->transactional(function () use($streamName) {
                $this->inMemoryStore->delete($streamName);
            });
        } else {
            $this->inMemoryStore->delete($streamName);
        }
    }

    public function fetchStreamMetadata(StreamName $streamName): array
    {
        return $this->inMemoryStore->fetchStreamMetadata($streamName);
    }

    public function hasStream(StreamName $streamName): bool
    {
        return $this->inMemoryStore->hasStream($streamName);
    }

    public function load(StreamName $streamName, int $fromNumber = 1, ?int $count = null, ?MetadataMatcher $metadataMatcher = null): Iterator
    {
        return $this->inMemoryStore->load($streamName, $fromNumber, $count, $metadataMatcher);
    }

    public function loadReverse(StreamName $streamName, ?int $fromNumber = null, ?int $count = null, ?MetadataMatcher $metadataMatcher = null): Iterator
    {
        return $this->inMemoryStore->loadReverse($streamName, $fromNumber, $count, $metadataMatcher);
    }

    /**
     * @return StreamName[]
     */
    public function fetchStreamNames(?string $filter, ?MetadataMatcher $metadataMatcher, int $limit = 20, int $offset = 0): array
    {
        return $this->inMemoryStore->fetchStreamNames($filter, $metadataMatcher, $limit, $offset);
    }

    /**
     * @return StreamName[]
     */
    public function fetchStreamNamesRegex(string $filter, ?MetadataMatcher $metadataMatcher, int $limit = 20, int $offset = 0): array
    {
        return $this->inMemoryStore->fetchStreamNamesRegex($filter, $metadataMatcher, $limit, $offset);
    }

    /**
     * @return string[]
     */
    public function fetchCategoryNames(?string $filter, int $limit = 20, int $offset = 0): array
    {
        return $this->inMemoryStore->fetchCategoryNames($filter, $limit, $offset);
    }

    /**
     * @return string[]
     */
    public function fetchCategoryNamesRegex(string $filter, int $limit = 20, int $offset = 0): array
    {
        return $this->inMemoryStore->fetchStreamNamesRegex($filter, $limit, $offset);
    }

    public function beginTransaction(): void
    {
        $this->transactionalConnection->beginTransaction();
    }

    public function commit(): void
    {
        $this->writeToFile();
        $this->transactionalConnection->commit();
    }

    public function rollback(): void
    {
        $this->transactionalConnection->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->transactionalConnection->inTransaction();
    }

    public function transactional(callable $callable)
    {
        $this->beginTransaction();

        try {
            $callable();
            $this->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /**
     * Copy stream information over to an original prooph InMemoryEventStore
     *
     * Required by prooph's InMemoryProjectionManager
     *
     * @return EventStore
     * @throws \ReflectionException
     */
    public function getInnerEventStore(): EventStore
    {
        return $this->inMemoryStore;
    }

    private function writeToFile(): void
    {
        $data = [
            'events' => $this->transactionalConnection['events'],
            'event_streams' => $this->transactionalConnection['event_streams'],
            'projections' => $this->transactionalConnection['projections'],
        ];

        foreach ($data['events'] as $streamName => $events) {
            $arrEvents = [];
            foreach ($events as $event) {
                $arrEvent = $this->messageConverter->convertToArray($event);
                $arrEvent['created_at'] = $arrEvent['created_at']->format('Y-m-d H:i:s.u');
                $arrEvents[] = $arrEvent;
            }

            $data['events'][$streamName] = $arrEvents;
        }

        $json = \json_encode($data, $this->jsonEncodeOptions);

        $err = \json_last_error();

        if($err !== JSON_ERROR_NONE) {
            throw FailedToWriteFile::with($this->filename, 'json_encode failed: ' . \json_last_error_msg());
        }

        if(false === file_put_contents($this->filename, $json)) {
            throw FailedToWriteFile::with($this->filename, 'file_put_contents failed.');
        }
    }
}
