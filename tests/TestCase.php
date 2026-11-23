<?php

namespace Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits(): array
    {
        $this->app->make(Migrator::class)->path(base_path('../job-management/database/migrations'));

        return parent::setUpTraits();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
