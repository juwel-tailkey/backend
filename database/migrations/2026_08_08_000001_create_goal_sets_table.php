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
        Schema::create('goal_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('goal_id')->unique();
            $table->string('goal_name');
            $table->string('goal_event_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['project_id', 'is_active']);
            $table->index('goal_event_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goal_sets');
    }
};
