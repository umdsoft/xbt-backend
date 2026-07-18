<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HONADON — Claude Vision tahlil natijasi. Tizim avtomatik qaror qiladi:
 * auto_confirmed / flagged / rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('mahalla')->hasTable('house_photo_analyses')) {
            return;
        }

        Schema::connection('mahalla')->create('house_photo_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('house_photo_id')->constrained('house_photos')->cascadeOnDelete()->comment('Kunlik rasm');
            $table->foreignUuid('baseline_photo_id')->nullable()->constrained('house_photos')->nullOnDelete();

            $table->boolean('same_house')->nullable();
            $table->decimal('confidence', 4, 3)->nullable()->comment('0..1');
            $table->boolean('cheating_suspected')->default(false);
            $table->json('changes')->nullable();
            $table->text('daily_work')->nullable()->comment('Bugun nima qilindi');
            $table->unsignedTinyInteger('progress_percent')->nullable();

            $table->enum('decision', ['pending', 'auto_confirmed', 'flagged', 'rejected'])->default('pending');
            $table->string('decision_reason', 500)->nullable();
            $table->json('raw_response')->nullable();

            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index('house_photo_id');
            $table->index('decision');
        });
    }

    public function down(): void
    {
        Schema::connection('mahalla')->dropIfExists('house_photo_analyses');
    }
};
