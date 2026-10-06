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
        Schema::create('atb_val', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('goal_id');
            $table->string('journey_id');
            $table->string('step_id');
            $table->decimal('atb_val', 10, 6); // Dashboard display value
            $table->decimal('removal_effect_correction', 10, 6)->nullable(); // Correction factor
            $table->timestamps();

            $table->foreign('goal_id')->references('goal_id')->on('goal_sets')->onDelete('cascade');
            $table->foreign('journey_id')->references('journey_id')->on('journeys')->onDelete('cascade');
            $table->unique(['journey_id', 'step_id']);
            $table->index(['project_id', 'goal_id']);
            $table->index('atb_val');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('atb_val');
    }
};
