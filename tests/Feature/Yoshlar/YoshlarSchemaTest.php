<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema qurilganini va ulanish to'g'ri sozlanganini tekshiradi.
 */
class YoshlarSchemaTest extends TestCase
{
    public function test_all_six_tables_exist(): void
    {
        $schema = Schema::connection('yoshlar');

        foreach (['sectors', 'organizations', 'staff', 'youth', 'audit_log', 'pii_access_log'] as $table) {
            $this->assertTrue($schema->hasTable($table), "yoshlar.{$table} jadvali yo'q");
        }
    }

    public function test_search_path_includes_master(): void
    {
        $this->assertSame(
            'yoshlar,master,public',
            config('database.connections.yoshlar.search_path'),
        );
    }

    public function test_youth_pii_columns_exist(): void
    {
        $columns = Schema::connection('yoshlar')->getColumnListing('youth');

        foreach (['pinfl', 'pinfl_hash', 'passport_series', 'passport_number'] as $column) {
            $this->assertContains($column, $columns);
        }
    }
}
