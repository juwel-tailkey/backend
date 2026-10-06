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
        Schema::create('journey_strength', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('goal_id');
            $table->string('journey_id');
            $table->decimal('strength_score', 10, 6); // Overall path quality score
            $table->decimal('goal_bonus', 10, 6)->nullable(); // Bonus for converting journeys
            $table->decimal('diversity', 10, 6)->nullable(); // Touchpoint diversity score
            $table->decimal('length_fit', 10, 6)->nullable(); // Optimal length fit
            $table->decimal('balance', 10, 6)->nullable(); // Step distribution balance
            $table->decimal('context', 10, 6)->nullable(); // Contextual relevance
            $table->string('strength_tier')->nullable(); // high_strength, moderate_strength, low_strength
            $table->timestamps();

            $table->foreign('goal_id')->references('goal_id')->on('goal_sets')->onDelete('cascade');
            $table->foreign('journey_id')->references('journey_id')->on('journeys')->onDelete('cascade');
            $table->unique(['project_id', 'journey_id']);
            $table->index(['project_id', 'goal_id']);
            $table->index('strength_score');
            $table->index('strength_tier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journey_strength');
    }
};
