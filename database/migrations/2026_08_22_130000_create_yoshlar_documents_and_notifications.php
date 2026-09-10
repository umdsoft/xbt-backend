<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TZ 5.7 (hujjat/media) va 5.8 (ogohlantirish) — F1–F5 da qolib ketgan ikki modul.
 *
 * HUJJAT: bitta jadval barcha obyektlar uchun (`entity_type` + `entity_id`) —
 * topshiriq, muammo, bandlik, otaliq, yosh. Alohida jadval yasash har modulga
 * bir xil yuklash/versiyalash/o'chirish kodini takrorlashni anglatardi.
 *
 * BILDIRISHNOMA: in-app. Har zanjir hodisasida (tasdiq kutilmoqda, qaytarildi,
 * muddat yaqin) mas'ulga yoziladi. Telegram/SMS keyin shu jadval ustiga ulanadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('yoshlar');

        $this->create($schema, 'documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // Polimorf bogʻlanish: task | case | employment | patronage | youth | protocol
            $t->string('entity_type', 20)->index();
            $t->uuid('entity_id')->index();
            $t->uuid('district_id')->nullable()->index();
            $t->string('category', 30)->default('boshqa');
            $t->string('original_name', 300);
            $t->string('stored_path', 500);
            $t->string('mime', 150)->nullable();
            $t->bigInteger('size')->default(0);
            $t->string('sha256', 64)->index();
            $t->smallInteger('version')->default(1);
            $t->uuid('uploaded_by');
            $t->timestamp('uploaded_at');
            $t->timestamps();
            $t->index(['entity_type', 'entity_id']);
        });

        $this->create($schema, 'notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('type', 40)->index();
            $t->string('title', 300);
            $t->text('body')->nullable();
            // SPA ichidagi manzil (masalan `/topshiriqlar/<uuid>`).
            $t->string('link', 300)->nullable();
            $t->string('entity_type', 20)->nullable();
            $t->uuid('entity_id')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at']);
        });

        // Bir xil hodisa uchun takroriy bildirishnoma yozilmasin: kunlik
        // muddat tekshiruvi har ishga tushganda yangi yozuv qoʻshsa,
        // foydalanuvchi bir xil xabarni oʻnlab marta koʻrardi.
        DB::connection('yoshlar')->statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS notifications_unique_event
             ON yoshlar.notifications (user_id, type, entity_id)
             WHERE read_at IS NULL AND entity_id IS NOT NULL"
        );
    }

    private function create(\Illuminate\Database\Schema\Builder $schema, string $table, callable $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }
};
