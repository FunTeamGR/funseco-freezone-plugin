<?php

namespace Funseco\Freezone\Tests;

use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Freezone\FreezoneServiceProvider;

abstract class TestCase extends CoreTestCase
{
    /** Dependencies first, then this plugin (isolated runs load only these). */
    protected function plugins(): array
    {
        return [
            FreezoneServiceProvider::class,
        ];
    }
}
