<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('hokim_yordamchilari')) {
            return;
        }

        Schema::connection('hr')->create('hokim_yordamchilari', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hokimlik_id')->constrained('departments')->restrictOnDelete()
                ->comment('Tenant — qaysi hokimlik');
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Tizim foydalanuvchisi (bo\'lsa)');
            $table->string('full_name_cyr', 255)->comment('Ф.И.Ш. (кирилл)');
            $table->string('phone', 20)->nullable();
            $table->enum('direction', [
                'iqtisodiyot', 'qurilish', 'qishloq', 'ijtimoiy', 'madaniyat', 'yoshlar', 'boshqa',
            ])->comment('Yo\'nalish');
            $table->foreignUuid('mahalla_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Mahalla bo\'yicha mas\'ul bo\'lsa');
            $table->date('start_date')->comment('Vazifa boshlanish sanasi');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('hokimlik_id');
            $table->index('direction');
            $table->index(['hokimlik_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('hokim_yordamchilari');
    }
};
