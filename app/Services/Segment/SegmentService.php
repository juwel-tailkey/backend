<?php

namespace App\Services\Segment;

use App\Models\Project;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

class SegmentService
{
    private const NUMERIC_OPERATORS = ['>', '>=', '<', '<=', '=', '!='];

    private const CATEGORICAL_OPERATORS = ['=', '!='];

    private const ATTRIBUTES = [
        'sessions' => 'numeric',
        'device_type' => 'categorical',
        'country' => 'categorical',
        'city' => 'categorical',
    ];

    private const DEVICE_TYPES = ['desktop', 'mobile', 'tablet'];

    public function list(Project $project): Collection
    {
        return $project->segments()
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Segment $segment) => $this->toArray($segment));
    }

    public function create(User $user, Project $project, Request $request): array
    {
        $validated = $this->validatePayload($request);

        $segment = $project->segments()->create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'color' => $validated['color'],
            'status' => $validated['status'],
            'conditions' => $validated['conditions'],
        ]);

        return $this->toArray($segment);
    }

    public function update(Segment $segment, Request $request): array
    {
        $validated = $this->validatePayload($request);

        $segment->update([
            'name' => $validated['name'],
            'color' => $validated['color'],
            'status' => $validated['status'],
            'conditions' => $validated['conditions'],
        ]);

        return $this->toArray($segment->fresh());
    }

    public function delete(Segment $segment): void
    {
        $segment->delete();
    }

    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32'],
            'status' => ['required', 'string', 'in:active,draft'],
            'conditions' => ['required', 'array', 'min:1'],
            'conditions.*.attribute' => ['required', 'string', 'in:'.implode(',', array_keys(self::ATTRIBUTES))],
            'conditions.*.operator' => ['required', 'string'],
            'conditions.*.value' => ['required'],
        ]);

        $conditions = [];
        foreach ($validated['conditions'] as $condition) {
            $conditions[] = $this->normalizeCondition($condition);
        }

        return [
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#3f6df0',
            'status' => $validated['status'],
            'conditions' => $conditions,
        ];
    }

    private function normalizeCondition(array $condition): array
    {
        $attribute = $condition['attribute'];
        $operator = (string) $condition['operator'];
        $type = self::ATTRIBUTES[$attribute];

        $allowedOperators = $type === 'numeric' ? self::NUMERIC_OPERATORS : self::CATEGORICAL_OPERATORS;

        if (! in_array($operator, $allowedOperators, true)) {
            throw new RuntimeException(sprintf('Operator "%s" is not valid for attribute "%s".', $operator, $attribute));
        }

        $value = $condition['value'];

        if ($type === 'numeric') {
            if (! is_numeric($value)) {
                throw new RuntimeException(sprintf('Attribute "%s" requires a numeric value.', $attribute));
            }
            $value = 0 + $value;
        } else {
            $value = trim((string) $value);
            if ($value === '') {
                throw new RuntimeException(sprintf('Attribute "%s" requires a value.', $attribute));
            }

            if ($attribute === 'device_type' && ! in_array(strtolower($value), self::DEVICE_TYPES, true)) {
                throw new RuntimeException('Device type must be one of: desktop, mobile, tablet.');
            }

            if ($attribute === 'device_type') {
                $value = strtolower($value);
            }
        }

        return [
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    private function toArray(Segment $segment): array
    {
        return [
            'id' => $segment->id,
            'name' => $segment->name,
            'color' => $segment->color,
            'status' => $segment->status,
            'conditions' => $segment->conditions ?? [],
            'createdAt' => $segment->created_at?->toIso8601String(),
        ];
    }
}
