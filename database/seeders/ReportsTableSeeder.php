<?php

namespace Database\Seeders;

use App\Models\Report;
use Illuminate\Database\Seeder;

class ReportsTableSeeder extends Seeder
{
    /**
     * Report catalog. `is_active` reports must also have a matching entry in
     * config/report_handlers.php for the direct-BigQuery path to work.
     *
     * Note: other report rows (e.g. "Top Converting Paths") may already exist
     * from earlier manual testing — this seeder only manages the reports it
     * is responsible for and won't touch unrelated rows.
     */
    public function run(): void
    {
        Report::query()->updateOrCreate(
            ['slug' => 'source-traffic'],
            [
                'title' => 'Source Traffic',
                'description' => 'Traffic broken down by source/medium channel.',
                'is_active' => true,
            ]
        );
    }
}
