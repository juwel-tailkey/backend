<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('active_project_id')
                ->nullable()
                ->after('email')
                ->constrained('projects')
                ->nullOnDelete();

            $table->index('active_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['active_project_id']);
            $table->dropColumn('active_project_id');
        });
    }
};
