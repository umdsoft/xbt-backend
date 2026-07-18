<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('yy_events')) {
            return;
        }

        Schema::connection('hr')->create('yy_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('yoshlar_yetakchisi_id')->constrained('yoshlar_yetakchilari')->cascadeOnDelete();
            $table->enum('event_type', ['sport', 'talim', 'manaviyat', 'ish', 'boshqa']);
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->date('event_date');
            $table->unsignedInteger('participants_count')->default(0);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['yoshlar_yetakchisi_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('yy_events');
    }
};
