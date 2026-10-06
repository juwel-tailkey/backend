<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_generators', function (Blueprint $table) {
            if (! Schema::hasColumn('report_generators', 'request_hash')) {
                $table->string('request_hash', 32)->nullable()->after('request_object');
                $table->index(['report_id', 'project_id', 'request_hash', 'status'], 'report_generators_reuse_lookup_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_generators', function (Blueprint $table) {
            $table->dropIndex('report_generators_reuse_lookup_index');
            $table->dropColumn('request_hash');
        });
    }
};
