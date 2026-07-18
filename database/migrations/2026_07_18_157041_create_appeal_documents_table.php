<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('appeal_documents')) {
            return;
        }

        Schema::connection('hr')->create('appeal_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appeal_id')->constrained('citizen_appeals')->cascadeOnDelete();
            $table->string('file_path', 500);
            $table->string('original_name', 255);
            $table->string('document_type', 100)->nullable()->comment('pasport, biznes-reja, ariza...');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type', 100)->nullable();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('appeal_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('appeal_documents');
    }
};
