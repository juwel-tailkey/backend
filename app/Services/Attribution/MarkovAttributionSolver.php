<?php

namespace App\Services\Attribution;

/**
 * Computes Markov-chain removal-effect attribution from first-order page
 * transition counts.
 *
 * States: __start__, page paths (and __other__), plus the absorbing states
 * __conversion__ and __null__. For each page we recompute the conversion
 * probability with that page removed (its inbound edges redirected to
 * __null__) and derive a removal effect and a normalized attribution value.
 */
class MarkovAttributionSolver
{
    public const START = '__start__';

    public const CONVERSION = '__conversion__';

    public const NULL_STATE = '__null__';

    public const OTHER = '__other__';

    private const SYNTHETIC = [self::START, self::CONVERSION, self::NULL_STATE, self::OTHER];

    private const MAX_ITERATIONS = 1000;

    private const CONVERGENCE = 1e-9;

    /**
     * @param  array<int, array{from: string, to: string, count: int|float}>  $transitions
     * @return array<string, array{removalEffect: float, attributionValue: float}>
     */
    public function solve(array $transitions): array
    {
        $counts = [];
        $states = [];

        foreach ($transitions as $transition) {
            $from = (string) ($transition['from'] ?? '');
            $to = (string) ($transition['to'] ?? '');
            $count = (float) ($transition['count'] ?? 0);

            if ($from === '' || $to === '' || $count <= 0 || $from === $to) {
                continue;
            }

            $counts[$from][$to] = ($counts[$from][$to] ?? 0) + $count;
            $states[$from] = true;
            $states[$to] = true;
        }

        if ($counts === []) {
            return [];
        }

        $baseline = $this->conversionProbability($counts, null);

        if ($baseline <= 0.0) {
            return [];
        }

        $pages = [];
        foreach (array_keys($states) as $state) {
            if (! in_array($state, self::SYNTHETIC, true)) {
                $pages[] = $state;
            }
        }

        $removalEffects = [];
        $totalRemoval = 0.0;

        foreach ($pages as $page) {
            $without = $this->conversionProbability($counts, $page);
            $effect = 1.0 - ($without / $baseline);
            $effect = max(0.0, min(1.0, $effect));

            $removalEffects[$page] = $effect;
            $totalRemoval += $effect;
        }

        $result = [];
        foreach ($removalEffects as $page => $effect) {
            $result[$page] = [
                'removalEffect' => $effect,
                'attributionValue' => $totalRemoval > 0.0 ? $effect / $totalRemoval : 0.0,
            ];
        }

        return $result;
    }

    /**
     * Probability of reaching __conversion__ starting from __start__.
     * When $removePage is set, edges into it are redirected to __null__ and
     * its outbound edges are dropped.
     *
     * @param  array<string, array<string, float>>  $counts
     */
    private function conversionProbability(array $counts, ?string $removePage): float
    {
        $prob = [];

        foreach ($counts as $from => $targets) {
            if ($removePage !== null && $from === $removePage) {
                continue;
            }

            $adjusted = [];
            $total = 0.0;

            foreach ($targets as $to => $count) {
                $target = ($removePage !== null && $to === $removePage) ? self::NULL_STATE : $to;

                if ($target === $from) {
                    continue;
                }

                $adjusted[$target] = ($adjusted[$target] ?? 0) + $count;
                $total += $count;
            }

            if ($total <= 0.0) {
                continue;
            }

            foreach ($adjusted as $to => $count) {
                $prob[$from][$to] = $count / $total;
            }
        }

        $c = [];

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS; $iteration++) {
            $maxDelta = 0.0;

            foreach ($prob as $from => $targets) {
                $sum = 0.0;

                foreach ($targets as $to => $p) {
                    if ($to === self::CONVERSION) {
                        $sum += $p;
                    } elseif ($to === self::NULL_STATE) {
                        continue;
                    } else {
                        $sum += $p * ($c[$to] ?? 0.0);
                    }
                }

                $delta = abs($sum - ($c[$from] ?? 0.0));
                if ($delta > $maxDelta) {
                    $maxDelta = $delta;
                }

                $c[$from] = $sum;
            }

            if ($maxDelta < self::CONVERGENCE) {
                break;
            }
        }

        return $c[self::START] ?? 0.0;
    }
}
