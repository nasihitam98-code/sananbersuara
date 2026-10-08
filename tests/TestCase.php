<?php

namespace Tests;

use App\Services\Voting\StatusPublisher;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private int $startedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startedAt = time();
    }

    /**
     * File status statis (public/status/*.json) yang dibuat selama tes dihapus lagi, agar folder public
     * tidak menumpuk ribuan file sisa tes.
     */
    protected function tearDown(): void
    {
        foreach (glob(public_path(StatusPublisher::DIRECTORY.'/*.json')) ?: [] as $file) {
            if (@filemtime($file) >= $this->startedAt) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }
}
