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
        Schema::create('attribution_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('goal_id');
            $table->string('journey_id');
            $table->string('step_id'); // Touchpoint identifier
            $table->string('model_name'); // linear, first_click, last_click, time_decay, markov, shapley, dataset_removal
            $table->decimal('raw_score', 10, 6); // Raw attribution score
            $table->timestamps();

            $table->foreign('goal_id')->references('goal_id')->on('goal_sets')->onDelete('cascade');
            $table->foreign('journey_id')->references('journey_id')->on('journeys')->onDelete('cascade');
            $table->unique(['journey_id', 'step_id', 'model_name']);
            $table->index(['project_id', 'goal_id']);
            $table->index('model_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribution_scores');
    }
};
