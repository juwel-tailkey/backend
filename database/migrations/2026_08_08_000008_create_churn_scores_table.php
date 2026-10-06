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
        Schema::create('churn_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('device_id');
            $table->decimal('churn_probability', 10, 6); // Churn risk score
            $table->string('risk_band')->nullable(); // high, medium, low
            $table->timestamp('scored_at'); // When score was calculated
            $table->string('model_version')->nullable(); // Model version used
            $table->json('features')->nullable(); // Feature values for debugging
            $table->timestamps();

            $table->unique(['project_id', 'device_id']);
            $table->index(['project_id', 'churn_probability']);
            $table->index('risk_band');
            $table->index('scored_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('churn_scores');
    }
};
