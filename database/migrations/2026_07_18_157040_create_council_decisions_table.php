<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('council_decisions')) {
            return;
        }

        Schema::connection('hr')->create('council_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appeal_id')->constrained('citizen_appeals')->cascadeOnDelete();
            $table->foreignUuid('council_id')->constrained('mahalla_councils')->restrictOnDelete();
            $table->date('meeting_date');
            $table->enum('decision_type', [
                'approve',     // Tasdiqlandi
                'reject',      // Rad etildi
                'partial',     // Qisman tasdiqlandi
                'escalate',    // Yuqoriga yo'naltirildi
                'info',        // Ma'lumot berildi
            ]);
            $table->text('decision_text');
            $table->json('voting_result')->nullable()->comment('A\'zolar bo\'yicha ovoz');
            $table->foreignUuid('decided_by')->constrained('users');
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['appeal_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('council_decisions');
    }
};
