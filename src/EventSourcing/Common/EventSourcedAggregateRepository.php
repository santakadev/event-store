<?php

namespace DDDominio\EventSourcing\Common;

use DDDominio\EventSourcing\Common\Annotation\AggregateId;
use DDDominio\EventSourcing\EventStore\EventStoreInterface;
use DDDominio\EventSourcing\Snapshotting\SnapshotStoreInterface;
use Doctrine\Common\Annotations\AnnotationReader;

class EventSourcedAggregateRepository
{
    /**
     * @var EventStoreInterface
     */
    private $eventStore;

    /**
     * @var SnapshotStoreInterface
     */
    private $snapshotStore;

    /**
     * @var AggregateReconstructor
     */
    private $aggregateReconstructor;

    /**
     * @var string
     */
    private $aggregateClass;

    /**
     * @param EventStoreInterface $eventStore
     * @param SnapshotStoreInterface $snapshotStore
     * @param AggregateReconstructor $aggregateReconstructor
     * @param string $aggregateClass
     */
    public function __construct(
        EventStoreInterface $eventStore, SnapshotStoreInterface $snapshotStore, $aggregateReconstructor, $aggregateClass
    ) {
        $this->eventStore = $eventStore;
        $this->snapshotStore = $snapshotStore;
        $this->aggregateReconstructor = $aggregateReconstructor;
        $this->aggregateClass = $aggregateClass;
    }

    /**
     * @param EventSourcedAggregateRoot $aggregate
     */
    public function add($aggregate)
    {
        $this->eventStore->appendToStream(
            $this->streamIdFromAggregate($aggregate),
            $aggregate->changes()
        );
        $aggregate->clearChanges();
    }

    /**
     * @param EventSourcedAggregateRoot $aggregate
     */
    public function save($aggregate)
    {
        $this->eventStore->appendToStream(
            $this->streamIdFromAggregate($aggregate),
            $aggregate->changes(),
            $aggregate->originalVersion()
        );
        $aggregate->clearChanges();
    }

    /**
     * @param string $id
     * @return EventSourcedAggregateRoot
     */
    public function findById($id)
    {
        $snapshot = $this->snapshotStore
            ->findLastSnapshot($this->aggregateClass, $id);

        $streamId = $this->streamIdFromAggregateId($id);
        if ($snapshot) {
            $stream = $this->eventStore
                ->readStreamEventsForward($streamId, $snapshot->version() + 1);
        } else {
            $stream = $this->eventStore->readFullStream($streamId);
        }

        return $this->aggregateReconstructor->reconstitute(
            $this->aggregateClass,
            $stream,
            $snapshot
        );
    }

    /**
     * @param string $id
     * @param int $version
     * @return EventSourcedAggregateRoot
     */
    public function findByIdAndVersion($id, $version)
    {
        $snapshot = $this->snapshotStore
            ->findNearestSnapshotToVersion($this->aggregateClass, $id, $version);

        $streamId = $this->streamIdFromAggregateId($id);
        if ($snapshot) {
            $stream = $this->eventStore
                ->readStreamEventsForward(
                    $streamId,
                    $snapshot->version() + 1,
                    $version - $snapshot->version()
                );
        } else {
            $stream = $this->eventStore
                ->readStreamEventsForward(
                    $streamId,
                    1,
                    $version
                );
        }
        return $this->aggregateReconstructor->reconstitute(
            $this->aggregateClass,
            $stream,
            $snapshot
        );
    }

    /**
     * @param EventSourcedAggregateRoot $aggregate
     * @return string
     */
    private function streamIdFromAggregate($aggregate)
    {
        return $this->streamIdFromAggregateId($this->aggregateId($aggregate));
    }

    /**
     * @param string $aggregateId
     * @return string
     */
    protected function streamIdFromAggregateId($aggregateId)
    {
        return $this->aggregateClass . '-' . $aggregateId;
    }

    /**
     * @param EventSourcedAggregateRoot $aggregate
     * @return string
     * @throws \Exception
     */
    private function aggregateId($aggregate)
    {
        if (method_exists($aggregate, 'id')) {
            return (string) $aggregate->id();
        }
        if (method_exists($aggregate, 'getId')) {
            return (string) $aggregate->getId();
        }
        $reflection = new \ReflectionClass($aggregate);
        $annotationReader = new AnnotationReader();
        $aggregateIdMethodName = null;
        foreach ($reflection->getMethods() as $reflectionMethod) {
            $annotation = $annotationReader->getMethodAnnotation(
                $reflectionMethod,
                AggregateId::class
            );
            if (!is_null($annotation)) {
                $aggregateIdMethodName = $reflectionMethod->getName();
                break;
            }
        }
        if (is_null($aggregateIdMethodName)) {
            throw new \RuntimeException('No method "id", "getId" or with "@AggregateId" annotation found in '. get_class($aggregate));
        }
        return (string) $aggregate->{$aggregateIdMethodName}();
    }
}
