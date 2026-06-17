<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parse_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('disk')->nullable();
            $table->string('source_path');
            $table->string('output_path');
            $table->string('status')->default('pending');
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('credits_used')->nullable();
            $table->text('error')->nullable();
            $table->nullableMorphs('parsable');
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parse_requests');
    }
};
