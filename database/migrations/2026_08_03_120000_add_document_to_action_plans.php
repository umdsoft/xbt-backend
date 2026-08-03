<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — chora-tadbirlar rejasiga TASDIQLOVCHI HUJJAT (reja yaratilганда majburiy
 * yuklanadi). Maxfiy disk (faqat vakolatли route orqali ochiladi — project/report
 * fayllari naqshi). Forward-only, pgsql-guard, idempotent (hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');

        if (! $schema->hasTable('action_plans')) {
            return;
        }

        $schema->table('action_plans', function (Blueprint $t) use ($schema) {
            if (! $schema->hasColumn('action_plans', 'document_path')) {
                $t->string('document_path')->nullable();
                $t->string('document_name')->nullable();
                $t->string('document_mime', 120)->nullable();
                $t->unsignedBigInteger('document_size')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');
        if ($schema->hasTable('action_plans') && $schema->hasColumn('action_plans', 'document_path')) {
            $schema->table('action_plans', function (Blueprint $t) {
                $t->dropColumn(['document_path', 'document_name', 'document_mime', 'document_size']);
            });
        }
    }
};
