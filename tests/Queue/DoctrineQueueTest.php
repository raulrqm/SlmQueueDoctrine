<?php

namespace SlmQueueDoctrineTest\Queue;

use DateTime;
use DateTimeZone;
use DateInterval;
use SlmQueue\Job\JobPluginManager;
use SlmQueueDoctrine\Exception\JobNotFoundException;
use SlmQueueDoctrine\Exception\LogicException;
use SlmQueueDoctrine\Options\DoctrineOptions;
use SlmQueueDoctrine\Queue\DoctrineQueue;
use SlmQueueDoctrineTest\Asset\SimpleJob;
use SlmQueueDoctrineTest\Framework\TestCase;
use SlmQueueDoctrineTest\Util\ServiceManagerFactory;
use Zend\ServiceManager\ServiceManager;

class DoctrineQueueTest extends TestCase
{
    /**
     * @var \SlmQueueDoctrine\Queue\DoctrineQueue
     */
    protected $queue;

    public function setUp()
    {
        parent::setUp();

        $this->createDb();

        $options     = new DoctrineOptions();

        $this->queue = new DoctrineQueue($this->getEntityManager()->getConnection(), $options, 'some-queue-name',
            ServiceManagerFactory::getServiceManager()->get(JobPluginManager::class));
    }

    public function tearDown()
    {
        $this->dropDb();
    }

    public function testBuriedLifetimeOption()
    {
        // defaults disabled
        static::assertEquals(DoctrineQueue::LIFETIME_DISABLED, $this->queue->getOptions()->getBuriedLifetime());

        $this->queue->getOptions()->setBuriedLifetime(10);
        static::assertEquals(10, $this->queue->getOptions()->getBuriedLifetime());
    }

    public function testDeletedLifetimeOption()
    {
        // defaults disabled
        static::assertEquals(DoctrineQueue::LIFETIME_DISABLED, $this->queue->getOptions()->getDeletedLifetime());

        $this->queue->getOptions()->setDeletedLifetime(10);
        static::assertEquals(10, $this->queue->getOptions()->getDeletedLifetime());
    }

    public function testJobCanBePushed()
    {
        $job = new SimpleJob();

        $this->queue->push($job);

        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT count(*) as count FROM queue_default')->fetch();

        static::assertEquals(1, $result['count']);
    }

    public function testPushPop()
    {
        $job = new SimpleJob();
        $this->queue->push($job);

        $poppedJob = $this->queue->pop();

        static::assertEquals($job, $poppedJob);
    }

    public function testPopHighestPriority()
    {
        $jobA = new SimpleJob();
        $this->queue->push($jobA, [
            'priority' => 10,
        ]);

        $jobB = new SimpleJob();
        $this->queue->push($jobB, [
            'priority' => 5,
        ]);

        $jobC = new SimpleJob();
        $this->queue->push($jobC, [
            'priority' => 20,
        ]);

        static::assertEquals($jobB, $this->queue->pop());
        static::assertEquals($jobA, $this->queue->pop());
        static::assertEquals($jobC, $this->queue->pop());
    }

    public function testJobCanBePushedMoreThenOnce()
    {
        $job = new SimpleJob();

        $this->queue->push($job);
        $this->queue->push($job);

        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT count(*) as count FROM queue_default')->fetch();

        static::assertEquals(2, $result['count']);
    }

    public function testPushDefaults()
    {
        $job = new SimpleJob();
        static::assertNull($job->getId(), "Upon job instantiation its id should be null");

        $this->queue->push($job);
        static::assertTrue(is_numeric($job->getId()), "After a job has been pushed its id should should be an id");

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        static::assertEquals('some-queue-name', $result['queue'], "The queue-name is expected to be stored.");
        static::assertEquals(DoctrineQueue::STATUS_PENDING, $result['status'], "The status of a new job should be pending.");

        static::assertEquals($result['created'], $result['scheduled'],
            "By default a job should be scheduled the same time it was created");
    }

    public function dataProvider_PushScheduledOptions()
    {
        $now = new DateTime('1970-01-01 00:01:40');

        return array(
            array(array('scheduled'=>100), '1970-01-01 00:01:40.000000'),
            array(array('scheduled'=>100, 'delay'=>10), '1970-01-01 00:01:50.000000'), // delay is added to scheduled
            array(array('scheduled'=>'100'), '1970-01-01 00:01:40.000000'),
            array(array('scheduled'=>'1970-01-01 00:01:40'), '1970-01-01 00:01:40.000000'),
            array(array('scheduled'=>'1970-01-01 00:01:40+03:00'), '1970-01-01 00:01:40.000000'),
            array(array('scheduled'=>$now), $now->format('Y-m-d H:i:s.u')),
        );
    }

    /**
     * @dataProvider dataProvider_PushScheduledOptions
     */
    public function testPushOptions_Scheduled($testOptions, $expectedResult)
    {
        $this->queue->push(new SimpleJob, $testOptions);

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        static::assertEquals($expectedResult, $result['scheduled'],
            "The job has not been scheduled correctly");
    }

    public function dataProvider_PushDelayOptions()
    {
        return array(
            array(array('delay'=>100), 100),
            array(array('delay'=>"100"), 100),
            array(array('delay'=>"PT100S"), 100),
            array(array('delay'=>"PT2H"), 7200),
            array(array('delay'=>"2 weeks"), 1209600),
            array(array('delay'=>new DateInterval("PT100S")), 100),
        );
    }

    /**
     * @dataProvider dataProvider_PushDelayOptions
     */
    public function testPushOptions_Delay($testOptions, $expectedResult)
    {
        $this->queue->push(new SimpleJob, $testOptions);

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        $created = new DateTime($result['created']);
        $scheduled = new DateTime($result['scheduled']);

        static::assertEquals($expectedResult, $scheduled->getTimestamp() - $created->getTimestamp(),
            "The job has not been scheduled correctly");
    }

    public function testPopBecomesPending()
    {

        $job = new SimpleJob();

        $this->queue->push($job);

        $returnedJob = $this->queue->pop();

        static::assertNotNull($returnedJob, "A job should have been returned.");

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        static::assertEquals(DoctrineQueue::STATUS_RUNNING, $result['status'], "The status of a popped should be running.");
        static::assertTrue((bool) preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result['executed']),
            "The executed field of a popped job should be set to a datetime");
    }

    public function testPopsCorrectlyScheduled()
    {
        $job = new SimpleJob();
        $returnedCount = 0;

        $now = new DateTime(null, new DateTimeZone(date_default_timezone_get()));
        $this->queue->push($job, array('scheduled' =>  time() + $now->getOffset() + 10));
        static::assertNull($this->queue->pop(), "Job is not due yet.");

        $this->queue->push($job, array('scheduled' => time() + $now->getOffset() + 10)); // must not be returned
        $this->queue->push($job, array('scheduled' => time() + $now->getOffset() - 10));$returnedCount++;
        $this->queue->push($job, array('scheduled' => time() + $now->getOffset() - 100));$returnedCount++;

        $firstJobId = $job->getId();
        $this->queue->push($job, array('scheduled' => time() + $now->getOffset()  - 50));$returnedCount++;
        $this->queue->push($job, array('scheduled' => time() + $now->getOffset()  - 30));$returnedCount++;
        $this->queue->push($job, array('delay' => 100)); // must not be returned
        $this->queue->push($job, array('delay' => -90)); $returnedCount++;

        $jobs = array();
        while ($job = $this->queue->pop()) {
            $jobs[] = $job;
        }

        static::assertEquals($firstJobId, $jobs[0]->getId(), "Job with the oldest scheduled date is expected to be popped first.");
        static::assertEquals($returnedCount, count($jobs), "The number of popped jobs is incorrect.");
    }

    public function testDelete_WithZeroLifeTimeShouldBeInstant()
    {
        $job = new SimpleJob();

        $this->queue->getOptions()->setDeletedLifetime(DoctrineQueue::LIFETIME_DISABLED);
        $this->queue->push($job);

        $this->queue->delete($job);

        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT count(*) as count FROM queue_default')->fetch();

        static::assertEquals(0, $result['count']);
    }

    public function testDelete_WithLifeTimeShouldMarked()
    {
        $job = new SimpleJob();

        $this->queue->getOptions()->setDeletedLifetime(10);
        $this->queue->push($job);

        $this->queue->pop(); // why must the job be running?

        $this->queue->delete($job);

        // count
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT count(*) as count FROM queue_default')->fetch();

        static::assertEquals(1, $result['count']);

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        static::assertEquals(DoctrineQueue::STATUS_DELETED, $result['status'], "The status of this job should be 'deleted'.");
    }

    public function testDelete_WithUnlimitedLifeTimeShouldMarked()
    {
        $job = new SimpleJob();

        $this->queue->getOptions()->setDeletedLifetime(DoctrineQueue::LIFETIME_UNLIMITED);
        $this->queue->push($job);

        $this->queue->pop(); // why must the job be running?

        $this->queue->delete($job);

        // count
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT count(*) as count FROM queue_default')->fetch();

        static::assertEquals(1, $result['count']);

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        static::assertEquals(DoctrineQueue::STATUS_DELETED, $result['status'], "The status of this job should be 'deleted'.");
    }

    public function testDelete_RaceCondition()
    {
        $job = new SimpleJob();

        $this->queue->getOptions()->setDeletedLifetime(10);
        $this->queue->push($job);

        $this->queue->pop(); // why must the job be running?

        $this->queue->delete($job);

        $this->setExpectedException(LogicException::class, 'Race-condition detected');
        $this->queue->delete($job);
    }

    public function testBury_WithZeroLifeTimeShouldBeInstant()
    {
        $job = new SimpleJob();

        $this->queue->getOptions()->setBuriedLifetime(DoctrineQueue::LIFETIME_DISABLED);
        $this->queue->push($job);

        $this->queue->bury($job);

        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT count(*) as count FROM queue_default')->fetch();

        static::assertEquals(0, $result['count']);
    }

    public function testBury_Options()
    {
        $job = new SimpleJob();

        $this->queue->getOptions()->setBuriedLifetime(10);

        $this->queue->push($job);
        $this->queue->pop(); // why must the job be running?
        $this->queue->bury($job);

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        static::assertNull($result['message'], "The message of this job should be 'null'.");
        static::assertNull($result['trace'], "The message of this job should be 'null'.");

        $this->queue->push($job);
        $this->queue->pop(); // why must the job be running?
        $this->queue->bury($job, array('message'=>'hi', 'trace'=>'because'));

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        static::assertContains('hi', $result['message']);
        static::assertNotNull('because', $result['trace']);

    }

    public function testBury_WithLifeTimeShouldMarked()
    {
        $job = new SimpleJob();

        $this->queue->getOptions()->setBuriedLifetime(10);
        $this->queue->push($job);

        $this->queue->pop(); // why must the job be running?

        $this->queue->bury($job);

        // count
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT count(*) as count FROM queue_default')->fetch();

        static::assertEquals(1, $result['count']);

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        static::assertEquals(DoctrineQueue::STATUS_BURIED, $result['status'], "The status of this job should be 'buried'.");
        static::assertTrue((bool) preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result['finished']),
            "The finished field of a buried job should be set to a datetime");

    }

    public function testBury_WithUnlimitedLifeTimeShouldMarked()
    {
        $job = new SimpleJob();

        $this->queue->getOptions()->setBuriedLifetime(DoctrineQueue::LIFETIME_UNLIMITED);
        $this->queue->push($job);

        $this->queue->pop(); // why must the job be running?

        $this->queue->bury($job);

        // count
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT count(*) as count FROM queue_default')->fetch();

        static::assertEquals(1, $result['count']);

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        static::assertEquals(DoctrineQueue::STATUS_BURIED, $result['status'], "The status of this job should be 'buried'.");
        static::assertTrue((bool) preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result['finished']),
            "The finished field of a buried job should be set to a datetime");
    }

    public function testBury_RaceCondition()
    {
        $job = new SimpleJob();

        $this->queue->getOptions()->setBuriedLifetime(10);
        $this->queue->push($job);

        $this->queue->pop(); // why must the job be running?

        $this->queue->bury($job);

        $this->setExpectedException(LogicException::class, 'Race-condition detected');
        $this->queue->bury($job);
    }

    public function testPeek()
    {
        $job = new SimpleJob();
        $this->queue->push($job);

        $peekedJob = $this->queue->peek($job->getId());

        static::assertEquals($job, $peekedJob);
    }

    public function testPeek_NonExistent()
    {
        $this->setExpectedException(JobNotFoundException::class);

        $this->queue->peek(1);
    }

    public function testRelease()
    {
        $job = new SimpleJob();
        $this->queue->push($job);

        $job = $this->queue->pop();

        $this->queue->release($job);

        // fetch last added job
        $result = $this->getEntityManager()->getConnection()
            ->query('SELECT * FROM queue_default ORDER BY id DESC LIMIT 1')->fetch();

        static::assertEquals(DoctrineQueue::STATUS_PENDING, $result['status'], "The status of a released job should be 'pending'.");

        static::assertTrue((bool) preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result['finished']),
            "The finished field of a released job should be set to a datetime");
    }

    public function testRelease_RaceCondition()
    {
        $job = new SimpleJob();

        $this->setExpectedException(LogicException::class, 'Race-condition detected');
        $this->queue->release($job);
    }
}
