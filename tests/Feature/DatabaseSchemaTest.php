<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifica a estrutura criada pelas migrations (extensões, colunas, chaves e constraints).
 */
class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_btree_gist_extension_is_enabled_after_migration(): void
    {
        $this->assertCount(1, DB::select("SELECT 1 FROM pg_extension WHERE extname = 'btree_gist'"));
    }

    public function test_users_company_id_is_required_indexed_foreign_key(): void
    {
        $column = collect(Schema::getColumns('users'))->firstWhere('name', 'company_id');

        $this->assertSame('int8', $column['type_name']);
        $this->assertFalse($column['nullable']);

        $foreignKey = collect(Schema::getForeignKeys('users'))->firstWhere('columns', ['company_id']);

        $this->assertSame('companies', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertTrue(Schema::hasIndex('users', ['company_id']));
    }

    public function test_skeleton_tables_remain_intact(): void
    {
        foreach (['password_reset_tokens', 'sessions', 'cache', 'jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing skeleton table [{$table}].");
        }
    }

    public function test_tenant_tables_expose_company_id_id_unique_key(): void
    {
        foreach (['professionals', 'customers', 'services'] as $table) {
            $index = collect(Schema::getIndexes($table))->firstWhere('name', "{$table}_company_id_id_unique");

            $this->assertNotNull($index, "Missing unique key on [{$table}].");
            $this->assertTrue($index['unique']);
            $this->assertSame(['company_id', 'id'], $index['columns']);
        }
    }

    public function test_professional_service_has_composite_cascading_foreign_keys(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('professional_service'));

        $toProfessionals = $foreignKeys->firstWhere('columns', ['company_id', 'professional_id']);
        $this->assertSame('professionals', $toProfessionals['foreign_table']);
        $this->assertSame(['company_id', 'id'], $toProfessionals['foreign_columns']);
        $this->assertSame('cascade', $toProfessionals['on_delete']);

        $toServices = $foreignKeys->firstWhere('columns', ['company_id', 'service_id']);
        $this->assertSame('services', $toServices['foreign_table']);
        $this->assertSame(['company_id', 'id'], $toServices['foreign_columns']);
        $this->assertSame('cascade', $toServices['on_delete']);

        $indexes = collect(Schema::getIndexes('professional_service'));
        $this->assertTrue($indexes->firstWhere('primary', true)['columns'] === ['professional_id', 'service_id']);
        $this->assertSame(
            ['company_id', 'professional_id', 'service_id'],
            $indexes->firstWhere('name', 'professional_service_company_professional_service_unique')['columns'],
        );
        $this->assertTrue(Schema::hasIndex('professional_service', ['service_id']));
    }

    public function test_bookings_have_composite_restricting_foreign_keys_and_indexes(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('bookings'));

        $toCustomers = $foreignKeys->firstWhere('columns', ['company_id', 'customer_id']);
        $this->assertSame('customers', $toCustomers['foreign_table']);
        $this->assertSame(['company_id', 'id'], $toCustomers['foreign_columns']);
        $this->assertSame('restrict', $toCustomers['on_delete']);

        $toOffer = $foreignKeys->firstWhere('columns', ['company_id', 'professional_id', 'service_id']);
        $this->assertSame('professional_service', $toOffer['foreign_table']);
        $this->assertSame('restrict', $toOffer['on_delete']);

        $this->assertNull($foreignKeys->firstWhere('columns', ['professional_id']));
        $this->assertNull($foreignKeys->firstWhere('columns', ['service_id']));
        $this->assertSame('booking_statuses', $foreignKeys->firstWhere('columns', ['status_id'])['foreign_table']);

        $this->assertTrue(Schema::hasIndex('bookings', ['company_id', 'customer_id']));
        $this->assertTrue(Schema::hasIndex('bookings', ['company_id', 'professional_id', 'service_id']));
        $this->assertTrue(Schema::hasIndex('bookings', ['professional_id', 'starts_at']));

        $columns = collect(Schema::getColumns('bookings'))->keyBy('name');
        $this->assertSame('timestamptz', $columns['starts_at']['type_name']);
        $this->assertSame('timestamptz', $columns['ends_at']['type_name']);
        $this->assertSame("'1'::bigint", $columns['status_id']['default']);

        foreach (['company_id', 'professional_id', 'customer_id', 'service_id', 'status_id', 'starts_at', 'ends_at'] as $column) {
            $this->assertFalse($columns[$column]['nullable'], "bookings.{$column} must be NOT NULL.");
        }
    }

    public function test_check_constraints_exist(): void
    {
        $constraints = DB::table('pg_constraint')
            ->where('contype', 'c')
            ->whereIn('conname', ['services_duration_positive', 'bookings_ends_after_starts'])
            ->pluck('conname')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['bookings_ends_after_starts', 'services_duration_positive'], $constraints);
    }

    public function test_bookings_no_overlap_is_an_exclusion_constraint(): void
    {
        $this->assertSame(
            'x',
            DB::table('pg_constraint')->where('conname', 'bookings_no_overlap')->value('contype'),
        );
    }
}
