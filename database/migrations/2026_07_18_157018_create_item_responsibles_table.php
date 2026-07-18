<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('item_responsibles')) {
            return;
        }

        Schema::connection('hr')->create('item_responsibles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('control_plan_item_id')->constrained()->cascadeOnDelete();

            // Полиморфик масъул: ички ходим (user) ёки ташкилот (organization).
            $table->enum('assignee_type', ['user', 'organization'])->default('user')
                ->comment('Масъул тури');
            $table->uuid('assignee_id')->nullable()->comment('user.id ёки organization.id');

            // Эски user_id — орқага мослик учун (мавжуд режа бандлари); янги ёзувлар assignee_* дан фойдаланади.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('responsible_name', 255)->nullable()->comment('Масъул шахс/ташкилот номи (snapshot)');
            $table->string('responsible_position', 255)->nullable()->comment('Лавозими');
            $table->boolean('is_primary')->default(false)->comment('Асосий масъулми');
            $table->timestamps();

            $table->index('control_plan_item_id');
            $table->index('user_id');
            $table->index(['assignee_type', 'assignee_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('item_responsibles');
    }
};
