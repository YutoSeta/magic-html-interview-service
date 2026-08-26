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
        Schema::create('interview_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('site_id', 100)->index();
            $table->string('locale', 20)->default('ja');
            $table->string('status', 20)->index();
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->json('messages');
            $table->json('structured_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interview_sessions');
    }
};
