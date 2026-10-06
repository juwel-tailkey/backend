<?php

namespace App\Services\Setup;

use Illuminate\Support\Facades\Storage;

class SetupStore
{
    private const STATE_FILE = 'data/setup-state.json';

    public function read(): array
    {
        $content = Storage::disk('local')->get(self::STATE_FILE);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function write(array $state): array
    {
        Storage::disk('local')->put(
            self::STATE_FILE,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );

        return $state;
    }

    public function update(callable $updater): array
    {
        $currentState = $this->read();
        $nextState = $updater($currentState);

        return $this->write($nextState);
    }

    public function createAdminUser(array $input): array
    {
        return array_merge($input, [
            'id' => (string) (int) (microtime(true) * 1000),
            'status' => 'Active',
            'updatedAt' => now()->format('Y/m/d'),
        ]);
    }
}
