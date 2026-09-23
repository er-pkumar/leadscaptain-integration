<?php

declare(strict_types=1);

namespace Leadscaptain\Tests;

use Leadscaptain\LeadscaptainServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LeadscaptainServiceProvider::class];
    }
}
