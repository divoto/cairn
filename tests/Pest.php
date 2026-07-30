<?php

declare(strict_types=1);

use Divoto\Cairn\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Every test under tests/ runs against the testbench application defined in
| TestCase. ArchTest.php needs no application, but binding it here is
| harmless and keeps the configuration to a single line.
|
*/

pest()->extend(TestCase::class)->in(__DIR__);
