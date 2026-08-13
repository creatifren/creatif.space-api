<?php

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plans are seeded for every feature test: since Fase 5, "Free" is a row in
 * the database rather than a constant, so quota enforcement has nothing to
 * read without it. Seeding here rather than per-test keeps the existing
 * Space tests honest — they exercise the real Free ceilings.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(PlanSeeder::class))
    ->in('Feature');
