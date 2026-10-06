<?php

namespace App\Services\BigQuery;

use RuntimeException;

class SegmentFilterBuilder
{
    private const NUMERIC_OPERATORS = ['>', '>=', '<', '<=', '=', '!='];

    private const CATEGORICAL_OPERATORS = ['=', '!='];

    private const ATTRIBUTE_TYPES = [
        'sessions' => 'numeric',
        'device_type' => 'categorical',
        'country' => 'categorical',
        'city' => 'categorical',
    ];

    private const SESSION_COLUMNS = [
        'device_type' => 'seg_device',
        'country' => 'seg_country',
        'city' => 'seg_city',
    ];

    /**
     * Named value parameters shared across periods (seg_v0, seg_v1, ...).
     */
    public function params(?array $conditions): array
    {
        $params = [];

        foreach ($this->catalogConditions($conditions) as $index => $condition) {
            $value = $condition['value'];
            $params['seg_v'.$index] = self::ATTRIBUTE_TYPES[$condition['attribute']] === 'numeric'
                ? (int) $value
                : (string) $value;
        }

        return $params;
    }

    /**
     * Returns an "AND {sessionKeyExpr} IN ( ... )" fragment scoping rows to the
     * segment's qualifying sessions for the given period, or '' when no conditions.
     */
    public function sessionPredicate(
        ?array $conditions,
        string $tableFqn,
        string $startParam,
        string $endParam,
        string $sessionKeyExpr
    ): string {
        $conditions = $this->catalogConditions($conditions);

        if ($conditions === []) {
            return '';
        }

        $sessionAttrClauses = [];
        $userClauses = [];

        foreach ($conditions as $index => $condition) {
            $param = '@seg_v'.$index;
            $operator = $this->safeOperator($condition);

            if ($condition['attribute'] === 'sessions') {
                $userClauses[] = "c {$operator} {$param}";

                continue;
            }

            $column = self::SESSION_COLUMNS[$condition['attribute']];
            $sessionAttrClauses[] = "{$column} {$operator} {$param}";
        }

        $attrSql = '';
        foreach ($sessionAttrClauses as $clause) {
            $attrSql .= "\n        AND {$clause}";
        }

        $userSql = '';
        if ($userClauses !== []) {
            $userConditions = implode(' AND ', $userClauses);
            $userSql = <<<SQL

        AND user_pseudo_id IN (
          SELECT user_pseudo_id FROM (
            SELECT
              user_pseudo_id,
              COUNT(DISTINCT ga_session_id) AS c
            FROM (
              SELECT
                user_pseudo_id,
                COALESCE(CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'ga_session_id') AS STRING), '') AS ga_session_id
              FROM {$tableFqn}
              WHERE _TABLE_SUFFIX BETWEEN {$startParam} AND {$endParam}
            )
            WHERE ga_session_id != ''
            GROUP BY user_pseudo_id
          )
          WHERE {$userConditions}
        )
SQL;
        }

        return <<<SQL

    AND {$sessionKeyExpr} IN (
      SELECT DISTINCT CONCAT(user_pseudo_id, '-', ga_session_id)
      FROM (
        SELECT
          user_pseudo_id,
          COALESCE(CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'ga_session_id') AS STRING), '') AS ga_session_id,
          device.category AS seg_device,
          geo.country AS seg_country,
          geo.city AS seg_city
        FROM {$tableFqn}
        WHERE _TABLE_SUFFIX BETWEEN {$startParam} AND {$endParam}
      )
      WHERE ga_session_id != ''{$attrSql}{$userSql}
    )
SQL;
    }

    private function safeOperator(array $condition): string
    {
        $type = self::ATTRIBUTE_TYPES[$condition['attribute']];
        $allowed = $type === 'numeric' ? self::NUMERIC_OPERATORS : self::CATEGORICAL_OPERATORS;
        $operator = (string) ($condition['operator'] ?? '');

        if (! in_array($operator, $allowed, true)) {
            throw new RuntimeException(sprintf('Unsupported segment operator "%s".', $operator));
        }

        return $operator;
    }

    /**
     * Keep only recognised catalog attributes and re-index for stable param naming.
     */
    private function catalogConditions(?array $conditions): array
    {
        if (! $conditions) {
            return [];
        }

        $filtered = array_filter(
            $conditions,
            static fn ($condition) => is_array($condition)
                && isset($condition['attribute'])
                && array_key_exists($condition['attribute'], self::ATTRIBUTE_TYPES)
        );

        return array_values($filtered);
    }
}
