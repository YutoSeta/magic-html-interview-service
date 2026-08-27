<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('interview_idempotency_records', function (Blueprint $table) {
            $table->char('scoped_key_hash', 64)->primary();
            $table->char('request_hash', 64);
            $table->string('operation', 100);
            $table->uuid('interview_session_id')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();

            $table->index(['interview_session_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interview_idempotency_records');
    }
};
