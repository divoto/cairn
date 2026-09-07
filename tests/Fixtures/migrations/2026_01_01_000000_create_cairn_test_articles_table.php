<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A host-application table for the tests that attribute analytics to a model.
 *
 * Named so that nothing could mistake it for a real table: the suite can be
 * pointed at any database, and `articles` is a name a host is likely to own.
 *
 * It is a migration rather than a `beforeEach` so that it lands outside the
 * transaction RefreshDatabase wraps each test in. CREATE TABLE is DDL, and
 * MySQL and MariaDB commit implicitly when they run it — which discards the
 * savepoint the test is supposed to roll back to and fails every later test
 * in the file with "SAVEPOINT does not exist".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cairn_test_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cairn_test_articles');
    }
};
