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
        Schema::create('assembly_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('goal_id');
            $table->string('journey_id');
            $table->string('step_id');
            $table->decimal('assembly_score', 10, 6); // Combined 7-model score
            $table->timestamps();

            $table->foreign('goal_id')->references('goal_id')->on('goal_sets')->onDelete('cascade');
            $table->foreign('journey_id')->references('journey_id')->on('journeys')->onDelete('cascade');
            $table->unique(['journey_id', 'step_id']);
            $table->index(['project_id', 'goal_id']);
            $table->index('assembly_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assembly_scores');
    }
};
