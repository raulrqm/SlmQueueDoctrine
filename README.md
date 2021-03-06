SlmQueueDoctrine
================

[![Latest Stable Version](https://poser.pugx.org/slm/queue-doctrine/v/stable.png)](https://packagist.org/packages/slm/queue-doctrine)
[![Latest Unstable Version](https://poser.pugx.org/slm/queue-doctrine/v/unstable.png)](https://packagist.org/packages/slm/queue-doctrine)

Created by Stefan Kleff

Requirements
------------
* [Zend Framework 2](https://github.com/zendframework/zf2)
* [SlmQueue](https://github.com/juriansluiman/SlmQueue)
* [Doctrine 2 ORM Module](https://github.com/doctrine/DoctrineORMModule)


Installation
------------

First, install SlmQueue ([instructions here](https://github.com/juriansluiman/SlmQueue/blob/master/README.md)). Then,
add the following line into your `composer.json` file:

```json
"require": {
	"slm/queue-doctrine": "^2.0"
}
```

Then, enable the module by adding `SlmQueueDoctrine` in your application.config.php file.

Documentation
-------------

Before reading SlmQueueDoctrine documentation, please read [SlmQueue documentation](https://github.com/juriansluiman/SlmQueue).

### Configuring the connection

You need to register a doctrine connection which SlmQueueDoctrine will use to access the database into the service manager. Here is some more [information](https://github.com/doctrine/DoctrineORMModule#connection-settings).

Connection parameters can be defined in the application configuration:

```php
<?php
return array(
    'doctrine' => array(
        'connection' => array(
            // default connection name
            'orm_default' => array(
                'driverClass' => 'Doctrine\DBAL\Driver\PDOMySql\Driver',
                'params' => array(
                    'host'     => 'localhost',
                    'port'     => '3306',
                    'user'     => 'username',
                    'password' => 'password',
                    'dbname'   => 'database',
                )
            )
        )
    ),
);
```

### Creating the table from SQL file

You must create the required table that will contain the queue's you may use the schema located in 'data/queue_default.sql'. If you change the table name look at [Configuring queues](./#configuring-queues)

```
>mysql database < data/queue_default.sql
```
### Creating the table from Doctrine Entity
There is an alternative way to create 'queue_default' table in your database by copying Doctrine Entity 'date/DefaultQueue.php' to your entity folder ('Application\Entity' in our example) and executing Doctrine's 'orm:schema-tool:update' command which should create the table for you. Notice that DefaultQueue entity is only used for table creation and is not used by this module internally.


### Adding queues

```php
return array(
  'slm_queue' => array(
    'queue_manager' => array(
      'factories' => array(
        'foo' => 'SlmQueueDoctrine\Factory\DoctrineQueueFactory'
      )
    )
  )
);
```
### Adding jobs

```php
return array(
  'slm_queue' => array(
    'job_manager' => array(
      'factories' => array(
        'My\Job' => 'My\JobFactory'
      )
    )
  )
);

``` 
### Configuring queues

The following options can be set per queue ;
	
- connection (defaults to 'doctrine.connection.orm_default') : Name of the registered doctrine connection service
- table_name (defaults to 'queue_default') : Table name which should be used to store jobs
- deleted_lifetime (defaults to 0) : How long to keep deleted (successful) jobs (in minutes)
- buried_lifetime (defaults to 0) : How long to keep buried (failed) jobs (in minutes)


```php
return array(
  'slm_queue' => array(
    'queues' => array(
      'foo' => array(
        // ...
      )
    )
  )
);
 ```
 
Provided Worker Strategies
--------------------------

In addition to the provided strategies by [SlmQueue](https://github.com/juriansluiman/SlmQueue/blob/master/docs/6.Events.md) SlmQueueDoctrine comes with these strategies;

#### ClearObjectManagerStrategy

This strategy will clear the ObjectManager before execution of individual jobs. The job must implement the ObjectManagerAwareInterface.

listens to:

- `process.job` event at priority 1000

options:

- none

This strategy is enabled by default.

#### IdleNapStrategy

When no jobs are available in the queue this strategy will make the worker wait for a specific amount time before quering the database again.

listens to:

- `process.idle` event at priority 1

options:

- `nap_duration` defaults to 1 (second)

This strategy is enabled by default.

### Operations on queues

#### push

Valid options are:

* scheduled: the time when the job will be scheduled to run next
	* numeric string or integer - interpreted as a timestamp
	* string parserable by the DateTime object
	* DateTime instance
* delay: the delay before a job become available to be popped (defaults to 0 - no delay -)
	* numeric string or integer - interpreted as seconds
	* string parserable (ISO 8601 duration) by DateTimeInterval::__construct
	* string parserable (relative parts) by DateTimeInterval::createFromDateString
	* DateTimeInterval instance
* priority: the lower the priority is, the sooner the job get popped from the queue (default to 1024)

Examples:
```php
	// scheduled for execution asap
    $queue->push($job);
    
    // will get executed before jobs that have higher priority
    $queue->push($job, [
        'priority' => 200,
    ]);

	// scheduled for execution 2015-01-01 00:00:00 (system timezone applies)
    $queue->push($job, array(
        'scheduled' => 1420070400,
    ));

    // scheduled for execution 2015-01-01 00:00:00 (system timezone applies)
    $queue->push($job, array(
        'scheduled' => '2015-01-01 00:00:00'
    ));

    // scheduled for execution at 2015-01-01 01:00:00
    $queue->push($job, array(
        'scheduled' => '2015-01-01 00:00:00',
        'delay' => 3600
    ));  

    // scheduled for execution at now + 300 seconds
    $queue->push($job, array(
        'delay' => 'PT300S'
    ));

    // scheduled for execution at now + 2 weeks (1209600 seconds)
    $queue->push($job, array(
        'delay' => '2 weeks'
    ));

    // scheduled for execution at now + 300 seconds
    $queue->push($job, array(
        'delay' => new DateInterval("PT300S"))
    ));
```


### Worker actions

Interact with workers from the command line from within the public folder of your Zend Framework 2 application

#### Starting a worker
Start a worker that will keep monitoring a specific queue for jobs scheduled to be processed. This worker will continue until it has reached certain criteria (exceeds a memory limit or has processed a specified number of jobs).

`php index.php queue doctrine <queueName> --start`

A worker will exit when you press cntr-C *after* it has finished the current job it is working on. (PHP doesn't support signal handling on Windows)

*Warning : In previous versions of SlmQueueDoctrine the worker would quit if there where no jobs available for 
processing. That meant you could savely create a cronjob that would start a worker every minute. If you do that now
you will quickly run out of available resources.

Now, you can let your script run indefinitely. While this was not possible in PHP versions previous to 5.3, it is now
not a big deal. This has the other benefit of not needing to bootstrap the application every time, which is good
for performance.
*

#### Recovering jobs

To recover jobs which are in the 'running' state for prolonged period of time (specified in minutes) use the following command.

`php index.php queue doctrine <queueName> --recover [--executionTime=]`

*Note : Workers that are processing a job that is being recovered are NOT stopped.*

