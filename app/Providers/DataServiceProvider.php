<?php

namespace App\Providers;

use App\Contracts\DataProvider;
use App\Services\Data\BigQueryDataProvider;
use App\Services\Data\MockDataProvider;
use Illuminate\Support\ServiceProvider;

class DataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DataProvider::class, function () {
            if (config('bigquery.data_provider') === 'bigquery') {
                return new BigQueryDataProvider();
            }

            return new MockDataProvider();
        });
    }
}
