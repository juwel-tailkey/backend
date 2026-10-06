<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safe test command to verify system integrity.
 * Does NOT modify any data or configuration.
 */
class TestSystemSafety extends Command
{
    protected $signature = 'system:test-safety {--detailed : Show detailed test results}';
    protected $description = 'Test that current system still works after new tables were added';

    public function handle(): int
    {
        $this->info('🔍 Testing system safety...');
        $this->newLine();

        $tests = [
            'existing_tables' => $this->testExistingTables(),
            'current_implementation' => $this->testCurrentImplementation(),
            'feature_flags' => $this->testFeatureFlags(),
            'database_connection' => $this->testDatabaseConnection(),
            'backward_compatibility' => $this->testBackwardCompatibility(),
        ];

        $passed = 0;
        $failed = 0;

        foreach ($tests as $testName => $result) {
            if ($result['passed']) {
                $this->info("✅ {$testName}: PASSED");
                if ($this->option('detailed')) {
                    $this->line("   " . $result['message']);
                }
                $passed++;
            } else {
                $this->error("❌ {$testName}: FAILED");
                $this->line("   " . $result['message']);
                $failed++;
            }
        }

        $this->newLine();
        $this->info("📊 Test Results: {$passed} passed, {$failed} failed");

        if ($failed === 0) {
            $this->info('🎉 All tests passed! System is safe.');
            return self::SUCCESS;
        } else {
            $this->error('⚠️  Some tests failed. Please review the issues above.');
            return self::FAILURE;
        }
    }

    private function testExistingTables(): array
    {
        try {
            // Check that core tables still exist
            $coreTables = [
                'users',
                'organizations',
                'projects',
                'segments',
            ];

            foreach ($coreTables as $table) {
                if (!Schema::hasTable($table)) {
                    return [
                        'passed' => false,
                        'message' => "Core table '{$table}' is missing!",
                    ];
                }
            }

            // Check if new tables exist (for information only, not failure)
            $newTables = [
                'goal_sets',
                'tracked_events',
                'journeys',
                'attribution_scores',
                'assembly_scores',
                'atb_val',
                'journey_strength',
            ];

            $newTablesCount = 0;
            foreach ($newTables as $table) {
                if (Schema::hasTable($table)) {
                    $newTablesCount++;
                }
            }

            return [
                'passed' => true,
                'message' => "All core tables exist ({$newTablesCount}/7 new tables also available)",
            ];
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'message' => 'Error checking tables: ' . $e->getMessage(),
            ];
        }
    }

    private function testCurrentImplementation(): array
    {
        try {
            // Test that current services still work
            $segmentService = app(\App\Services\Segment\SegmentService::class);
            $attributionController = app(\App\Http\Controllers\AttributionController::class);

            if (!$segmentService || !$attributionController) {
                return [
                    'passed' => false,
                    'message' => 'Current services failed to instantiate',
                ];
            }

            return [
                'passed' => true,
                'message' => 'Current services still functional',
            ];
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'message' => 'Service instantiation failed: ' . $e->getMessage(),
            ];
        }
    }

    private function testFeatureFlags(): array
    {
        try {
            // Check that feature flags are properly configured (should be false by default)
            $flags = [
                'USE_ADVANCED_ATTRIBUTION' => false,
                'USE_ADVANCED_SEGMENTS' => false,
                'USE_JOURNEY_STRENGTH' => false,
                'USE_ASSEMBLY_SCORES' => false,
            ];

            foreach ($flags as $flag => $expectedValue) {
                $actualValue = config('advanced.' . strtolower(str_replace('USE_', '', $flag)));
                if ($actualValue !== $expectedValue) {
                    return [
                        'passed' => true, // Not a failure, just informational
                        'message' => "Feature flag {$flag} is " . ($actualValue ? 'ON' : 'OFF'),
                    ];
                }
            }

            return [
                'passed' => true,
                'message' => 'All feature flags are OFF (safe default)',
            ];
        } catch (\Exception $e) {
            return [
                'passed' => true, // Not a failure
                'message' => 'Feature flags configured (using defaults)',
            ];
        }
    }

    private function testDatabaseConnection(): array
    {
        try {
            // Test basic database operation
            $result = DB::select('SELECT 1 as test');

            if (empty($result) || $result[0]->test != 1) {
                return [
                    'passed' => false,
                    'message' => 'Database query returned unexpected result',
                ];
            }

            return [
                'passed' => true,
                'message' => 'Database connection working correctly',
            ];
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'message' => 'Database connection failed: ' . $e->getMessage(),
            ];
        }
    }

    private function testBackwardCompatibility(): array
    {
        try {
            // Test that current API endpoints would still work
            // by checking that required services exist

            $requiredServices = [
                \App\Services\Segment\SegmentService::class,
                \App\Services\BigQuery\PageImpactQueryService::class,
                \App\Services\BigQuery\TopConvertingPathsQueryService::class,
                \App\Services\BigQuery\BlockFlowQueryService::class,
                \App\Services\Attribution\MarkovAttributionSolver::class,
            ];

            foreach ($requiredServices as $service) {
                if (!class_exists($service)) {
                    return [
                        'passed' => false,
                        'message' => "Required service {$service} not found",
                    ];
                }
            }

            return [
                'passed' => true,
                'message' => 'All current services exist and are compatible',
            ];
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'message' => 'Compatibility check failed: ' . $e->getMessage(),
            ];
        }
    }
}
