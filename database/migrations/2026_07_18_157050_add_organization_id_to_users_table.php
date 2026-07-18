<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ташкилот фойдаланувчилари (tashkilot-admin / tashkilot-xodimi) учун
 * organization_id. Бундай фойдаланувчилар department_id'siз бўлиши мумкин;
 * уларнинг hokimlik_id'си organization.hokimlik_id'дан олинади.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('users', 'organization_id')) {
            return;
        }

        Schema::connection('hr')->table('users', function (Blueprint $table) {
            $table->foreignUuid('organization_id')
                ->nullable()
                ->after('position_id')
                ->constrained('organizations')
                ->nullOnDelete();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
