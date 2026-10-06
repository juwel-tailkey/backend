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
        Schema::create('tracked_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('event_key');
            $table->string('event_name');
            $table->string('event_category'); // intent, conversion, engagement, etc.
            $table->json('parameters')->nullable(); // Optional parameter matching rules
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'event_key']);
            $table->index(['project_id', 'event_category']);
            $table->index('event_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracked_events');
    }
};
