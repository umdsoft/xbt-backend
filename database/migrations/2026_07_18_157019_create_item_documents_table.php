<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('item_documents')) {
            return;
        }

        Schema::connection('hr')->create('item_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('control_plan_item_id')->constrained()->cascadeOnDelete();
            $table->string('file_path', 500);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('control_plan_item_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('item_documents');
    }
};
