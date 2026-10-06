<?php

return [
    /**
     * Advanced Attribution Feature Flags
     * All features default to OFF for safety
     */
    'use_advanced_attribution' => env('USE_ADVANCED_ATTRIBUTION', false),

    'use_advanced_segments' => env('USE_ADVANCED_SEGMENTS', false),

    'use_journey_strength' => env('USE_JOURNEY_STRENGTH', false),

    'use_assembly_scores' => env('USE_ASSEMBLY_SCORES', false),

    /**
     * Assembly Score Configuration
     */
    'assembly_score' => [
        // High assembly threshold (top quartile)
        'high_threshold' => 0.75,

        // Models to combine
        'models' => [
            'linear',
            'first_click',
            'last_click',
            'time_decay',
            'markov',
            'shapley',
            'dataset_removal_effect',
        ],
    ],

    /**
     * Journey Strength Configuration
     */
    'journey_strength' => [
        'high_threshold' => 0.75,
        'moderate_threshold' => 0.45,
    ],

    /**
     * Path-Page Engagement Configuration
     */
    'path_page_engagement' => [
        'half_life_steps' => 3,
        'time_score_cap_seconds' => 300, // 5 minutes
        'gap_threshold' => 0.3,
    ],

    /**
     * Time-Bound Drop-Off Configuration
     */
    'time_bound_drop_off' => [
        'min_wait_days' => 3,
        'fallback_retarget_deadline_days' => 14,
        'marginal_return_floor' => 0.01,
    ],

    /**
     * Consideration Cycle Configuration
     */
    'consideration_cycle' => [
        'fast_hours' => 24,
        'moderate_hours' => 168,
        'slow_hours' => 168, // anything above moderate
    ],
];
