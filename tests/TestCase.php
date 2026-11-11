<?php

namespace Tests;

use App\Support\Media\MediaRetention;
use App\Support\Workspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Static per-request memos outlive the test that filled them, and
        // RefreshDatabase hands the same user ids to the next test: a
        // "stranger" could inherit the last test's team membership.
        Workspace::forget();
        MediaRetention::flush();
    }
}
