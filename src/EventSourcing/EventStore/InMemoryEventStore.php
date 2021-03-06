<?php

namespace DDDominio\EventSourcing\EventStore;

use DDDominio\EventSourcing\Common\EventStream;
use DDDominio\EventSourcing\Common\EventStreamInterface;
use DDDominio\EventSourcing\Serialization\SerializerInterface;
use DDDominio\EventSourcing\Versioning\EventUpgraderInterface;
use DDDominio\EventSourcing\Versioning\Version;

class InMemoryEventStore extends AbstractEventStore
{
    /**
     * @var IdentifiedEventStream[]
     */
    private $streams;

    /**
     * @param SerializerInterface $serializer
     * @param EventUpgraderInterface $eventUpgrader
     * @param IdentifiedEventStream[] $streams
     */
    public function __construct(
        SerializerInterface $serializer,
        EventUpgraderInterface $eventUpgrader,
        array $streams = []
    ) {
        parent::__construct($serializer, $eventUpgrader);
        $this->streams = $streams;
    }

    /**
     * @param string $streamId
     * @param StoredEvent[] $storedEvents
     * @param int $expectedVersion
     */
    protected function appendStoredEvents($streamId, $storedEvents, $expectedVersion)
    {
        if ($this->streamExists($streamId)) {
            $this->streams[$streamId] = $this->streams[$streamId]->append($storedEvents);
        } else {
            $this->streams[$streamId] = new IdentifiedEventStream($streamId, $storedEvents);
        }
    }

    /**
     * @param string $streamId
     * @return EventStreamInterface
     */
    public function readFullStream($streamId)
    {
        if ($this->streamExists($streamId)) {
            return $this->domainEventStreamFromStoredEvents($this->streams[$streamId]);
        } else {
            return EventStream::buildEmpty();
        }
    }

    /**
     * @param string $streamId
     * @param int $start
     * @param int $count
     * @return EventStreamInterface
     */
    public function readStreamEvents($streamId, $start = 1, $count = null)
    {
        if (!$this->streamExists($streamId)) {
            return EventStream::buildEmpty();
        }

        return $this->domainEventStreamFromStoredEvents(
            $this->streams[$streamId]->slice($start - 1, $count)
        );
    }

    /**
     * @return EventStreamInterface[]
     */
    public function readAllStreams()
    {
        $allStreams = [];
        foreach ($this->streams as $stream) {
            $allStreams[] = $this->domainEventStreamFromStoredEvents($stream);
        }
        return $allStreams;
    }

    /**
     * @return EventStreamInterface
     */
    public function readAllEvents()
    {
        $allEventsStream = EventStream::buildEmpty();
        foreach ($this->streams as $stream) {
            $streamEvents = $this->domainEventStreamFromStoredEvents($stream);
            $allEventsStream = $allEventsStream->append($streamEvents->events());
        }
        return $allEventsStream;
    }

    /**
     * @param string $streamId
     * @return int
     */
    protected function streamVersion($streamId)
    {
        return $this->streamExists($streamId) ?
            $this->streams[$streamId]->count() : 0;
    }

    /**
     * @param string $type
     * @param Version $version
     * @return EventStreamInterface
     */
    protected function readStoredEventsOfTypeAndVersion($type, $version)
    {
        $storedEvents = [];
        foreach ($this->streams as $stream) {
            /** @var StoredEvent $event */
            foreach ($stream as $event) {
                if ($event->type() === $type && $event->version()->equalTo($version)) {
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

    /**
     * @param string $streamId
     * @param \DateTimeImmutable $datetime
     * @return int
     * @throws EventStreamDoesNotExistException
     */
    public function getStreamVersionAt($streamId, \DateTimeImmutable $datetime)
    {
        if (!$this->streamExists($streamId)) {
            throw EventStreamDoesNotExistException::fromStreamId($streamId);
        }

        $filteredStoredEvents = $this->streams[$streamId]->filter(function(StoredEvent $event) use ($datetime) {
            return $event->occurredOn()->getTimestamp() <= $datetime->getTimestamp();
        });

        return count($filteredStoredEvents);
    }
}
