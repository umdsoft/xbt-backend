<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('item_documents', 'task_response_id')) {
            return;
        }

        Schema::connection('hr')->table('item_documents', function (Blueprint $table) {
            // NULL → топшириқнинг асосий файли; то'лдирилган → жавобга бириктирилган файл
            $table->foreignUuid('task_response_id')->nullable()->after('control_plan_item_id')
                ->constrained('task_responses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('item_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_response_id');
        });
    }
};
