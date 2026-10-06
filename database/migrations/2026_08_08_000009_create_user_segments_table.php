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
        Schema::create('user_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('device_id');
            $table->string('segment_family'); // general, attribution, churn
            $table->string('segment_type'); // acquisition, technology, high_assembly, etc.
            $table->string('segment_key'); // Specific segment identifier
            $table->json('segment_data')->nullable(); // Additional segment-specific data
            $table->timestamp('assigned_at'); // When device was assigned to segment
            $table->timestamp('expires_at')->nullable(); // When segment assignment expires
            $table->timestamps();

            $table->unique(['project_id', 'device_id', 'segment_key'], 'unique_device_segment');
            $table->index(['project_id', 'segment_family']);
            $table->index(['project_id', 'segment_type']);
            $table->index(['device_id', 'segment_family']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_segments');
    }
};
