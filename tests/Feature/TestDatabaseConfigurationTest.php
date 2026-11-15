<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseConfigurationTest extends TestCase
{
    public function test_tests_run_on_dedicated_postgres_database(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('agendamento_testing', DB::connection()->getDatabaseName());
        $this->assertSame('agendamento_testing', DB::scalar('select current_database()'));
    }
}
