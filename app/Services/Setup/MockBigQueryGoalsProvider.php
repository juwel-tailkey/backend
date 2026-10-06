<?php

namespace App\Services\Setup;

class MockBigQueryGoalsProvider
{
    public function getGoals(): array
    {
        return [
            [
                'id' => 'goal-registration',
                'path' => '/account/registration',
                'type' => 'Page load',
                'occurrences' => 47203,
            ],
            [
                'id' => 'goal-purchase',
                'path' => 'purchase',
                'type' => 'Event',
                'occurrences' => 47108,
            ],
            [
                'id' => 'goal-checkout',
                'path' => '/checkout',
                'type' => 'Page load',
                'occurrences' => 91440,
            ],
            [
                'id' => 'goal-begin-checkout',
                'path' => 'begin_checkout',
                'type' => 'Event',
                'occurrences' => 38812,
            ],
            [
                'id' => 'goal-register',
                'path' => '/account/register',
                'type' => 'Page load',
                'occurrences' => 12931,
            ],
            [
                'id' => 'goal-sign-up',
                'path' => 'sign_up',
                'type' => 'Event',
                'occurrences' => 8881,
            ],
        ];
    }
}
