<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('');
            $table->string('industry')->nullable();
            $table->string('timezone')->default('UTC -4:00 - Tokyo');
            $table->text('description')->nullable();
            $table->string('goal_type')->nullable();
            $table->unsignedTinyInteger('setup_step')->default(1);
            $table->boolean('setup_completed')->default(false);
            $table->string('goal_external_id')->nullable();
            $table->string('goal_path')->nullable();
            $table->string('goal_event_type')->nullable();
            $table->unsignedInteger('goal_occurrences')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
