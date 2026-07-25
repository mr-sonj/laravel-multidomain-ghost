<?php

namespace MrSonj\MultiDomainGhost\Tests;

use MrSonj\MultiDomainGhost\MultiDomainGhostServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MultiDomainGhostServiceProvider::class,
        ];
    }
}
