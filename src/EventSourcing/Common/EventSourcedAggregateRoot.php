<?php

namespace DDDominio\EventSourcing\Common;

abstract class EventSourcedAggregateRoot implements EventSourcedAggregateRootInterface
{
    /**
     * @var EventStream
     */
    private $changes;

    /**
     * @var int
     */
    private $version = 0;

    /**
     * @param mixed $domainEvent
     * @throws DomainEventNotUnderstandableException
     */
    public function applyAndRecord($domainEvent)
    {
        $domainEvent = $this->ensureDomainEvent($domainEvent);
        $this->apply($domainEvent);
        $this->record($domainEvent);
    }

    /**
     * Record a Domain Event
     *
     * @param mixed $domainEvent
     */
    private function record($domainEvent)
    {
        $this->ensureChangesEventStream();
        $this->changes = $this->changes->append($domainEvent);
    }

    private function ensureChangesEventStream()
    {
        if (is_null($this->changes)) {
            $this->clearChanges();
        }
    }

    /**
     * Apply a Domain Event to the aggregate
     *
     * @param mixed $domainEvent
     */
    public function apply($domainEvent)
    {
        $domainEvent = $this->ensureDomainEvent($domainEvent);
        $eventHandlerName = $this->getEventHandlerName($domainEvent);
        if (method_exists($this, $eventHandlerName)) {
            $this->executeEventHandler($this, $eventHandlerName, $domainEvent);
        } else {
            $this->applyRecursively($eventHandlerName, $domainEvent);
        }
    }

    /**
     * @param $domainEvent
     * @return DomainEvent
     */
    private function ensureDomainEvent($domainEvent)
    {
        if (!$domainEvent instanceof DomainEvent) {
            $domainEvent = DomainEvent::produceNow($domainEvent);
        }
        return $domainEvent;
    }

    /**
     * @param string $eventHandlerName
     * @param mixed $domainEventData
     * @throws DomainEventNotUnderstandableException
     */
    private function applyRecursively($eventHandlerName, $domainEventData)
    {
        $applied = false;
        $reflectedClass = new \ReflectionClass(get_class($this));
        foreach ($reflectedClass->getProperties() as $property) {
            $propertyValue = $this->{$property->getName()};
            if (is_object($propertyValue)) {
                if (method_exists($propertyValue, $eventHandlerName)) {
                    $this->executeEventHandler($propertyValue, $eventHandlerName, $domainEventData);
                    $applied = true;
                }
            }
            if (is_array($propertyValue)) {
                foreach ($propertyValue as $item) {
                    if (method_exists($item, $eventHandlerName)) {
                        $this->executeEventHandler($item, $eventHandlerName, $domainEventData);
                        $applied = true;
                    }
                }
            }
        }
        if (!$applied) {
            throw DomainEventNotUnderstandableException::fromAggreagteAndEventTypes(
                get_class($this),
                get_class($domainEventData)
            );
        }
    }

    /**
     * @param DomainEvent $domainEvent
     * @return string
     */
    private function getEventHandlerName($domainEvent)
    {
        return $this->eventHandlerPrefix() . (new \ReflectionClass($domainEvent->data()))->getShortName();
    }

    /**
     * @return string
     */
    protected function eventHandlerPrefix()
    {
        return 'when';
    }

    /**
     * @param object $entity
     * @param string $eventHandlerName
     * @param DomainEvent $domainEvent
     */
    private function executeEventHandler($entity, $eventHandlerName, $domainEvent)
    {
        $entity->{$eventHandlerName}($domainEvent->data(), $domainEvent->occurredOn());
        $this->increaseAggregateVersion();
    }

    private function increaseAggregateVersion()
    {
        $this->version++;
    }

    /**
     * Recorded stream of Domain Events
     *
     * @return EventStream
     */
    public function changes()
    {
        $this->ensureChangesEventStream();
        return $this->changes;
    }

    /**
     * Current version of the aggregate
     *
     * @return int
     */
    public function version()
    {
        return $this->version;
    }

    /**
     * The version of the aggregate before applying changes
     *
     * @return int
     */
    public function originalVersion()
    {
        return $this->version - count($this->changes());
    }

    /**
     * Removes recorded domain events
     */
    public function clearChanges()
    {
        $this->changes = EventStream::buildEmpty();
    }
}
