<?php

namespace DDDominio\EventSourcing\Versioning;

interface UpgradableEventStoreInterface
{
    /**
     * @param string $type
     * @param Version $from
     * @param Version $to
     */
    public function migrate($type, $from, $to);
}
