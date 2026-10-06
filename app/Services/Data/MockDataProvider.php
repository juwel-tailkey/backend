<?php

namespace App\Services\Data;

use App\Contracts\DataProvider;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;

class MockDataProvider implements DataProvider
{
    private function readJson(string $fileName): array
    {
        $content = Storage::disk('local')->get('data/'.$fileName);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function withProjectContext(array $payload, Project $project, string $titleKey = 'title', ?string $subtitleKey = 'subtitle'): array
    {
        if (isset($payload[$titleKey])) {
            $payload[$titleKey] = "{$payload[$titleKey]} — {$project->name}";
        }

        if ($subtitleKey && isset($payload[$subtitleKey])) {
            $payload[$subtitleKey] = "{$payload[$subtitleKey]} (current project)";
        }

        return $payload;
    }

    public function getSankey(Project $project): array
    {
        return $this->withProjectContext($this->readJson('sankey.json'), $project);
    }

    public function getPageviews(Project $project): array
    {
        return $this->withProjectContext($this->readJson('pageviews.json'), $project);
    }

    public function getCountrySessions(Project $project): array
    {
        return $this->withProjectContext($this->readJson('country-sessions.json'), $project);
    }

    public function getPagePaths(Project $project): array
    {
        return $this->withProjectContext($this->readJson('page-paths.json'), $project);
    }
}
