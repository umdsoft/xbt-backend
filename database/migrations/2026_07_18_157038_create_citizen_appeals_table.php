<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('citizen_appeals')) {
            return;
        }

        // duplicate_of_id — o'ziga-havola qiluvchi FK (PostgreSQL uchun alohida qo'shiladi).
        $isSqlite = Schema::connection('hr')->getConnection()->getDriverName() === 'sqlite';

        Schema::connection('hr')->create('citizen_appeals', function (Blueprint $table) use ($isSqlite) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('hokimlik_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('mahalla_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('youth_meeting_id')->nullable()->constrained('youth_meetings')->nullOnDelete();

            // Murojaatchi
            $table->string('applicant_name', 255);
            $table->string('applicant_phone', 30)->nullable();
            // Шифрланган қиймат ~200+ белги — TEXT керак (varchar(14) STRICT режимда хато берарди).
            $table->text('applicant_jshshir')->nullable()->comment('encrypted');
            $table->date('applicant_birth_date')->nullable();
            $table->string('applicant_address', 500)->nullable();

            // Kontent
            $table->text('body');
            $table->string('voice_path', 500)->nullable();
            $table->json('photo_paths')->nullable();
            $table->decimal('amount', 18, 2)->nullable()->comment('Pul/yer hajmi (talab bo\'lsa)');

            // Klassifikatsiya
            $table->foreignUuid('category_id')->nullable()->constrained('appeal_categories')->nullOnDelete();
            $table->foreignUuid('sub_category_id')->nullable()->constrained('appeal_categories')->nullOnDelete();
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');

            // Status va lifecycle
            $table->enum('status', [
                'draft', 'submitted', 'triaged', 'routed',
                'in_review', 'decided', 'completed', 'closed', 'reopened',
            ])->default('submitted');

            $table->enum('source', ['web', 'telegram', 'voice', 'paper', 'meeting'])->default('web');

            // AI metadata
            $table->decimal('ai_confidence', 5, 4)->nullable();
            if ($isSqlite) {
                $table->foreignUuid('duplicate_of_id')->nullable()->constrained('citizen_appeals')->nullOnDelete();
            } else {
                $table->uuid('duplicate_of_id')->nullable();
            }

            // SLA
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('sla_due_at')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('hokimlik_id');
            $table->index('mahalla_id');
            $table->index('status');
            $table->index('category_id');
            $table->index('priority');
            $table->index(['hokimlik_id', 'status']);
            $table->index('sla_due_at');
            $table->index('duplicate_of_id');
        });

        if (! $isSqlite) {
            Schema::connection('hr')->table('citizen_appeals', function (Blueprint $table) {
                $table->foreign('duplicate_of_id')->references('id')->on('citizen_appeals')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('citizen_appeals');
    }
};
