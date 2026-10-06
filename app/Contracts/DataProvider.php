<?php

namespace App\Contracts;

use App\Models\Project;

interface DataProvider
{
    public function getSankey(Project $project): array;

    public function getPageviews(Project $project): array;

    public function getCountrySessions(Project $project): array;

    public function getPagePaths(Project $project): array;
}
