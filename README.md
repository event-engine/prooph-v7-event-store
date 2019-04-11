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

//Basic set up that's able to handle Prooph\Common\Messaging\DomainEvent
$filesystemEventStore = new \EventEngine\Prooph\V7\EventStore\FilesystemEventStore(
    'data/prooph.event_store.json', 
    \JSON_PRETTY_PRINT
    // optional MessageFactory -> defaults to FQCNMessageFactory
    // optional MessageConverter -> defaults to NoOpMessageConverter
);

//Create an empty stream
$filesystemEventStore->create(
    new \Prooph\EventStore\Stream(
        new \Prooph\EventStore\StreamName('event_stream'), 
        new ArrayIterator()
    )
);

//Can also be used together with an InMemoryProjectionManager
$projectionManager = new \EventEngine\Prooph\V7\EventStore\Projecting\InMemory\InMemoryProjectionManager(
    $filesystemEventStore,
    new \EventEngine\Persistence\InMemoryConnection()
);

$query = $projectionManager->createQuery();

$query->fromStream('event_stream')
    ->whenAny(function (array $state, \Prooph\Common\Messaging\DomainEvent $event) {
        echo "{$event->messageName()} stored in event_stream\n";
    });

$query->run();
```

*Please Note: The combination of FilesystemEventStore and InMemoryProjectionManager has the drawback that projections only see events that are in the event store
at the time of running the projection. If other PHP processes add events to the store, those are first visible for the projection after restarting the PHP process and
running the projection again. We might add a FilesystemProjectionManager in the future, if needed.* 





