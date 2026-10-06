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
        Schema::create('journeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('goal_id');
            $table->string('device_id');
            $table->string('journey_id')->unique();
            $table->string('source_step'); // First touchpoint
            $table->integer('step_sequence'); // Position in journey
            $table->boolean('goal_achieved')->default(false);
            $table->timestamp('journey_ts'); // Journey start time
            $table->timestamp('goal_completed_ts')->nullable(); // When goal was achieved
            $table->timestamps();

            $table->foreign('goal_id')->references('goal_id')->on('goal_sets')->onDelete('cascade');
            $table->index(['project_id', 'goal_id']);
            $table->index(['device_id', 'goal_id']);
            $table->index('goal_achieved');
            $table->index('journey_ts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journeys');
    }
};
