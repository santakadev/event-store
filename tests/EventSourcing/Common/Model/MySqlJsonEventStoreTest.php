<?php

namespace tests\EventSourcing\Common\Model;

use Doctrine\Common\Annotations\AnnotationRegistry;
use EventSourcing\Common\Model\EventStream;
use EventSourcing\Common\Model\MysqlJsonEventStore;
use EventSourcing\Common\Model\Snapshot;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerBuilder;
use Tests\EventSourcing\Common\Model\TestData\DescriptionChanged;
use Tests\EventSourcing\Common\Model\TestData\DummyCreated;
use Tests\EventSourcing\Common\Model\TestData\DummyEventSourcedAggregate;
use Tests\EventSourcing\Common\Model\TestData\DummySnapshot;
use Tests\EventSourcing\Common\Model\TestData\NameChanged;

class MySqlJsonEventStoreTest extends \PHPUnit_Framework_TestCase
{
    const DB_HOST = 'localhost';
    const DB_USER = 'event_sourcing';
    const DB_PASS = 'event_sourcing123';
    const DB_NAME = 'json_event_store';

    /**
     * @var \PDO
     */
    private $connection;

    /**
     * @var Serializer
     */
    private $serializer;

    public function setUp()
    {
        $this->connection = new \PDO(
            'mysql:host=' . self::DB_HOST . ';dbname=' . self::DB_NAME,
            self::DB_USER,
            self::DB_PASS
        );
        $this->connection->query('TRUNCATE snapshots')->execute();
        $this->connection->query('TRUNCATE events')->execute();
        $this->connection->query('DELETE FROM streams')->execute();

        AnnotationRegistry::registerAutoloadNamespace(
            'JMS\Serializer\Annotation',
            __DIR__ . '/../../../../vendor/jms/serializer/src'
        );

        $this->serializer = SerializerBuilder::create()
            ->build();
    }

    /**
     * @test
     */
    public function appendAnEventToANewStreamShouldCreateAStreamContainingTheEvent()
    {
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $domainEvent = new NameChanged('name', new \DateTimeImmutable());

        $eventStore->appendToStream('streamId', [$domainEvent]);
        $stream = $eventStore->readFullStream('streamId');

        $this->assertInstanceOf(EventStream::class, $stream);
        $this->assertCount(1, $stream);
    }

    /**
     * @test
     */
    public function appendAnEventToAnExistentStream()
    {
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $domainEvent = new NameChanged('name', new \DateTimeImmutable());

        $eventStore->appendToStream('streamId', [$domainEvent]);
        $eventStore->appendToStream('streamId', [$domainEvent], 1);
        $stream = $eventStore->readFullStream('streamId');

        $this->assertCount(2, $stream);
    }

    /**
     * @test
     * @expectedException \EventSourcing\Common\Model\ConcurrencyException
     */
    public function ifTheExpectedVersionOfTheStreamDoesNotMatchWithRealVersionAConcurrencyExceptionShouldBeThrown()
    {
        $domainEvent = new NameChanged('name', new \DateTimeImmutable());
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $eventStore->appendToStream('streamId', [$domainEvent]);

        $eventStore->appendToStream('streamId', [$domainEvent]);
    }

    /**
     * @test
     * @expectedException \EventSourcing\Common\Model\EventStreamDoesNotExistException
     */
    public function whenAppendingToANewStreamIfAVersionIsSpecifiedAnExceptionShouldBeThrown()
    {
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $domainEvent = new NameChanged('name', new \DateTimeImmutable());

        $eventStore->appendToStream('newStreamId', [$domainEvent], 10);
    }

    /**
     * @test
     */
    public function readAnEventStream()
    {
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $domainEvent = new NameChanged('name', new \DateTimeImmutable());
        $eventStore->appendToStream('streamId', [$domainEvent]);

        $stream = $eventStore->readFullStream('streamId');

        $this->assertCount(1, $stream);
    }

    /**
     * @test
     */
    public function readAnEmptyStream()
    {
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );

        $stream = $eventStore->readFullStream('NonExistentStreamId');

        $this->assertTrue($stream->isEmpty());
        $this->assertCount(0, $stream);
    }

    /**
     * @test
     */
    public function findLastSnapshotOfAStream()
    {
        $snapshot = new DummySnapshot(
            'id',
            'name',
            'description',
            3
        );
        $lastSnapshot = new DummySnapshot(
            'id',
            'name',
            'description',
            10
        );
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $eventStore->addSnapshot($snapshot);
        $eventStore->addSnapshot($lastSnapshot);

        $retrievedSnapshot = $eventStore->findLastSnapshot(
            DummyEventSourcedAggregate::class,
            'id'
        );

        $this->assertInstanceOf(Snapshot::class, $retrievedSnapshot);
        $this->assertEquals(10, $retrievedSnapshot->version());
    }

    /**
     * @test
     */
    public function addAnSnapshot()
    {
        $snapshot = new DummySnapshot(
            'id',
            'name',
            'description',
            3
        );
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );

        $eventStore->addSnapshot($snapshot);

        $retrievedSnapshot = $eventStore->findLastSnapshot(
            DummyEventSourcedAggregate::class,
            'id'
        );
        $this->assertInstanceOf(Snapshot::class, $retrievedSnapshot);
    }

    /**
     * @test
     */
    public function findStreamEventsForward()
    {
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $eventStore->appendToStream('streamId', [
            new NameChanged('new name', new \DateTimeImmutable()),
            new DescriptionChanged('new description', new \DateTimeImmutable()),
            new NameChanged('another name', new \DateTimeImmutable()),
            new NameChanged('my name', new \DateTimeImmutable()),
        ]);

        $stream = $eventStore->readStreamEventsForward('streamId', 2);

        $this->assertCount(3, $stream);
        $events = $stream->events();
        $this->assertEquals('new description', $events[0]->description());
        $this->assertEquals('another name', $events[1]->name());
        $this->assertEquals('my name', $events[2]->name());
    }

    /**
     * @test
     */
    public function findStreamEventsForwardWithEventCount()
    {
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $eventStore->appendToStream('streamId', [
            new NameChanged('new name', new \DateTimeImmutable()),
            new DescriptionChanged('new description', new \DateTimeImmutable()),
            new NameChanged('another name', new \DateTimeImmutable()),
            new NameChanged('my name', new \DateTimeImmutable()),
        ]);

        $stream = $eventStore->readStreamEventsForward('streamId', 2, 2);

        $this->assertCount(2, $stream);
        $events = $stream->events();
        $this->assertEquals('new description', $events[0]->description());
        $this->assertEquals('another name', $events[1]->name());
    }

    /**
     * @test
     */
    public function findStreamEventsForwardShouldReturnEmptyStreamIfStartVersionIsGreaterThanStreamVersion()
    {
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $eventStore->appendToStream('streamId', [
            new NameChanged('new name', new \DateTimeImmutable()),
            new DescriptionChanged('new description', new \DateTimeImmutable()),
            new NameChanged('another name', new \DateTimeImmutable()),
            new NameChanged('my name', new \DateTimeImmutable()),
        ]);

        $stream = $eventStore->readStreamEventsForward('streamId', 5);

        $this->assertTrue($stream->isEmpty());
    }


    /**
     * @test
     */
    public function findSnapshotForEventVersion()
    {
        $domainEvents = [
            new DummyCreated('id', 'name', 'description', new \DateTimeImmutable()),
            new NameChanged('new name', new \DateTimeImmutable()),
            new DescriptionChanged('new description', new \DateTimeImmutable()),
            new NameChanged('another name', new \DateTimeImmutable()),
            new NameChanged('my name', new \DateTimeImmutable()),
        ];
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $eventStore->appendToStream('streamId', $domainEvents);
        $eventStore->addSnapshot(
            new DummySnapshot('id', 'new name', 'description', 2)
        );
        $eventStore->addSnapshot(
            new DummySnapshot('id', 'another name', 'new description', 4)
        );

        $snapshot = $eventStore->findNearestSnapshotToVersion(DummyEventSourcedAggregate::class, 'id', 3);

        $this->assertEquals(2, $snapshot->version());
    }

    /**
     * @test
     */
    public function findSnapshotForAnotherEventVersion()
    {
        $domainEvents = [
            new DummyCreated('id', 'name', 'description', new \DateTimeImmutable()),
            new NameChanged('new name', new \DateTimeImmutable()),
            new DescriptionChanged('new description', new \DateTimeImmutable()),
            new NameChanged('another name', new \DateTimeImmutable()),
            new NameChanged('my name', new \DateTimeImmutable()),
        ];
        $eventStore = new MysqlJsonEventStore(
            $this->connection,
            $this->serializer
        );
        $eventStore->appendToStream('streamId', $domainEvents);
        $eventStore->addSnapshot(
            new DummySnapshot('id', 'new name', 'description', 2)
        );
        $eventStore->addSnapshot(
            new DummySnapshot('id', 'another name', 'new description', 4)
        );

        $snapshot = $eventStore->findNearestSnapshotToVersion(DummyEventSourcedAggregate::class, 'id', 5);

        $this->assertEquals(4, $snapshot->version());
    }
}