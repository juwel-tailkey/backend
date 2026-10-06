<?php

// Maps a Report's `slug` to the ReportHandler responsible for building its
// data directly from BigQuery. Add an entry here whenever a new report type
// is wired into the generic ReportGenerator "direct" flow.

return [
    'source-traffic' => \App\Services\Report\SourceTrafficReportHandler::class,
    'top-converting-paths' => \App\Services\Report\ConvertingPathsReportHandler::class,
    'page-impact-report' => \App\Services\Report\PageImpactReportHandler::class,
    'block-view' => \App\Services\Report\BlockFlowReportHandler::class,
    'top-entry-points-to-conversion' => \App\Services\Report\EntryPointsReportHandler::class,
    'exit-report-from-conversion-path' => \App\Services\Report\ExitPointsReportHandler::class,
];
