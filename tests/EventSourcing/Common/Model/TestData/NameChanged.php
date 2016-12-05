<?php

namespace Tests\EventSourcing\Common\Model\TestData;

use EventSourcing\Versioning\Version;
use EventSourcing\Versioning\VersionableDomainEvent;
use JMS\Serializer\Annotation as Serializer;

class NameChanged implements VersionableDomainEvent
{
    /**
     * @var string
     *
     * @Serializer\Type("string")
     */
    private $name;

    /**
     * @var \DateTimeImmutable<’format’>
     *
     * @Serializer\Type("DateTimeImmutable<'Y-m-d H:i:s'>")
     */
    private $occurredOn;

    /**
     * @param string $name
     * @param \DateTimeImmutable $occurredOn
     */
    public function __construct($name, \DateTimeImmutable $occurredOn)
    {
        $this->name = $name;
        $this->occurredOn = $occurredOn;
    }

    /**
     * @return string
     */
    public function name()
    {
        return $this->name;
    }

    /**
     * @return \DateTimeImmutable
     */
    public function occurredOn()
    {
        return $this->occurredOn;
    }

    /**
     * @return Version
     */
    public function version()
    {
        return Version::fromString('3.0');
    }
}
