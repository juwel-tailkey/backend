<?php

return [
    'data_provider' => env('DATA_PROVIDER', 'mock'),
    'project_id' => env('BIGQUERY_PROJECT_ID'),
    'location' => env('BIGQUERY_LOCATION', 'US'),
    'client_origin' => env('CLIENT_ORIGIN', 'http://localhost:5173'),
    'ga4' => [
        'events_table' => env('BQ_GA4_EVENTS_TABLE', 'events_*'),
        'session_event' => env('BQ_GA4_SESSION_EVENT', 'session_start'),
        'page_param' => env('BQ_GA4_PAGE_PARAM', 'page_location'),
    ],
    'goals_cache_ttl' => (int) env('BQ_GOALS_CACHE_TTL', env('BQ_COUNTRY_SESSIONS_CACHE_TTL', 3600)),
    'goals_lookback_start' => env('BQ_GOALS_LOOKBACK_START', '2015-01-01'),
    'country_sessions_cache_ttl' => (int) env('BQ_COUNTRY_SESSIONS_CACHE_TTL', 3600),
    'pageviews_cache_ttl' => (int) env('BQ_PAGEVIEWS_CACHE_TTL', env('BQ_COUNTRY_SESSIONS_CACHE_TTL', 3600)),
    'entry_points_cache_ttl' => (int) env('BQ_ENTRY_POINTS_CACHE_TTL', env('BQ_COUNTRY_SESSIONS_CACHE_TTL', 3600)),
    'exit_points_cache_ttl' => (int) env('BQ_EXIT_POINTS_CACHE_TTL', env('BQ_COUNTRY_SESSIONS_CACHE_TTL', 3600)),
    'source_traffic_cache_ttl' => (int) env('BQ_SOURCE_TRAFFIC_CACHE_TTL', env('BQ_COUNTRY_SESSIONS_CACHE_TTL', 3600)),
    'page_impact_cache_ttl' => (int) env('BQ_PAGE_IMPACT_CACHE_TTL', env('BQ_COUNTRY_SESSIONS_CACHE_TTL', 3600)),
    'page_impact_max_pages' => (int) env('BQ_PAGE_IMPACT_MAX_PAGES', 75),
    'converting_paths_cache_ttl' => (int) env('BQ_CONVERTING_PATHS_CACHE_TTL', env('BQ_COUNTRY_SESSIONS_CACHE_TTL', 3600)),
    'converting_paths_max_steps' => (int) env('BQ_CONVERTING_PATHS_MAX_STEPS', 4),
    'block_flow_cache_ttl' => (int) env('BQ_BLOCK_FLOW_CACHE_TTL', env('BQ_COUNTRY_SESSIONS_CACHE_TTL', 3600)),
    'block_flow_max_pages' => (int) env('BQ_BLOCK_FLOW_MAX_PAGES', 40),
    'block_flow_max_columns' => (int) env('BQ_BLOCK_FLOW_MAX_COLUMNS', 6),
    'block_flow_max_actions' => (int) env('BQ_BLOCK_FLOW_MAX_ACTIONS', 4),
    'block_flow_source_cap' => (int) env('BQ_BLOCK_FLOW_SOURCE_CAP', 100),
];
