<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // response_object must be nullable: freshly-created generators start
        // with status=init and no result yet. Raw SQL avoids a doctrine/dbal
        // dependency just for a column modify.
        DB::statement('ALTER TABLE report_generators MODIFY response_object LONGTEXT NULL');
        DB::statement("ALTER TABLE report_generators MODIFY status VARCHAR(20) NOT NULL DEFAULT 'init'");
        DB::statement('ALTER TABLE report_generators MODIFY progress_percentage INT(11) NOT NULL DEFAULT 0');

        Schema::table('report_generators', function (Blueprint $table) {
            if (! $this->indexExists('report_generators', 'report_generators_project_id_status_index')) {
                $table->index(['project_id', 'status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_generators', function (Blueprint $table) {
            $table->dropIndex('report_generators_project_id_status_index');
        });

        DB::statement('ALTER TABLE report_generators MODIFY response_object LONGTEXT NOT NULL');
        DB::statement("ALTER TABLE report_generators MODIFY status VARCHAR(20) NOT NULL");
        DB::statement('ALTER TABLE report_generators MODIFY progress_percentage INT(11) NOT NULL');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);

        return count($result) > 0;
    }
};
