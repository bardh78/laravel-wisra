<?php

namespace Bardh78\LaravelWisra\Tests;

use Bardh78\LaravelWisra\LaravelWisraServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelWisraServiceProvider::class,
        ];
    }
}
