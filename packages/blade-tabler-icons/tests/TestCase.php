<?php

namespace Superbyte\BladeTablerIcons\Tests;

use BladeUI\Icons\BladeIconsServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Superbyte\BladeTablerIcons\BladeTablerIconsServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeTablerIconsServiceProvider::class,
        ];
    }
}
