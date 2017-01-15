<?php

namespace EventSourcing\Common;

use EventSourcing\Versioning\EventUpgrader;
use EventSourcing\Versioning\UpgradableEventStore;
use EventSourcing\Versioning\Version;
use JMS\Serializer\Serializer;

class InMemoryEventStore extends AbstractEventStore implements EventStore, UpgradableEventStore
{
    /**
     * @var StoredEventStream[]
     */
    private $streams;

    /**
     * @param Serializer $serializer
     * @param EventUpgrader $eventUpgrader
     * @param StoredEventStream[] $streams
     */
    public function __construct(
        $serializer,
        $eventUpgrader,
        array $streams = []
    ) {
        parent::__construct($serializer, $eventUpgrader);
        $this->streams = $streams;
    }

    /**
     * @param string $streamId
     * @param StoredEvent[] $storedEvents
     * @param int|null $expectedVersion
     */
    protected function appendStoredEvents($streamId, $storedEvents, $expectedVersion = null)
    {
        if ($this->streamExists($streamId)) {
            $this->streams[$streamId] = $this->streams[$streamId]->append($storedEvents);
        } else {
            $this->streams[$streamId] = new StoredEventStream($streamId, $storedEvents);
        }
    }

    /**
     * @param string $streamId
     * @return EventStream
     */
    public function readFullStream($streamId)
    {
        if ($this->streamExists($streamId)) {
            return $this->domainEventStreamFromStoredEvents(
                $this->streams[$streamId]->events()
            );
        } else {
            return EventStream::buildEmpty();
        }
    }

    /**
     * @param string $streamId
     * @param int $start
     * @param int $count
     * @return EventStream
     */
    public function readStreamEventsForward($streamId, $start = 1, $count = null)
    {
        if (!$this->streamExists($streamId)) {
            return EventStream::buildEmpty();
        }

        $storedEvents = $this->streams[$streamId]->events();

        if (isset($count)) {
            $filteredStoredEvents = array_splice($storedEvents, $start - 1, $count);
        } else {
            $filteredStoredEvents = array_splice($storedEvents, $start - 1);
        }
        return $this->domainEventStreamFromStoredEvents($filteredStoredEvents);
    }

    /**
     * @param string $streamId
     * @return int
     */
    protected function streamVersion($streamId)
    {
        return $this->streamExists($streamId) ?
            count($this->streams[$streamId]->events()) : 0;
    }

    /**
     * @param string $type
     * @param Version $version
     * @return EventStream
     */
    protected function readStoredEventsOfTypeAndVersion($type, $version)
    {
        $storedEvents = [];
        foreach ($this->streams as $stream) {
            /** @var StoredEvent $event */
            foreach ($stream as $event) {
                if ($event->name() === $type && $event->version()->equalTo($version)) {
                    $storedEvents[] = $event;
                }
            }
        }
        return new EventStream($storedEvents);
    }

    /**
     * @param string $streamId
     * @return bool
     */
    protected function streamExists($streamId)
    {
        return isset($this->streams[$streamId]);
    }
}