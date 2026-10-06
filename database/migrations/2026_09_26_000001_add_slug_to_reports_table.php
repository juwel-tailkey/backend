<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            if (! Schema::hasColumn('reports', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('title');
            }
        });

        DB::table('reports')->whereNull('slug')->orderBy('id')->get(['id', 'title'])->each(function ($report) {
            DB::table('reports')->where('id', $report->id)->update([
                'slug' => Str::slug($report->title),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            if (Schema::hasColumn('reports', 'slug')) {
                $table->dropColumn('slug');
            }
        });
    }
};
