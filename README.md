# event-engine/prooph-v7-event-store

Event Engine Prooph V7 Event Store Bindings

## Installation

```bash
composer require event-engine/prooph-v7-event-store
```

## Prooph Binding

tbd

## InMemoryEventStore

tbd

## FilesystemEventStore

A prooph v7 compatible `FilesystemEventStore` is included, too. It is meant to be used for demonstration purpose only (for example in a workshop).

```php

//Set up
$filesystemEventStore = new \EventEngine\Prooph\V7\EventStore\FilesystemEventStore(
    'data/prooph.event_store.json', 
    \JSON_PRETTY_PRINT
);

//Create an empty stream
$filesystemEventStore->create(
    new \Prooph\EventStore\Stream(
        new \Prooph\EventStore\StreamName('event_stream'), 
        new ArrayIterator()
    )
);

//Can also be used together with prooph's InMemoryProjectionManager
$projectionManager = new \Prooph\EventStore\Projection\InMemoryProjectionManager(
    $filesystemEventStore
);

$query = $projectionManager->createQuery();

$query->fromStream('event_stream')
    ->whenAny(function (\Prooph\Common\Messaging\Message $event) {
        echo "{$event->messageName()} stored in event_stream\n";
    });

$query->run();
```





