<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bigquery_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('gcp_project_id');
            $table->string('dataset_id')->nullable();
            $table->string('location')->default('US');
            $table->string('service_account_path')->nullable();
            $table->string('service_account_filename')->nullable();
            $table->boolean('is_connected')->default(false);
            $table->string('selected_report')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bigquery_connections');
    }
};
