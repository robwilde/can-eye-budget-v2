<?php

declare(strict_types=1);

use Tests\TestCase;

final class SchedulerHeartbeatTest extends TestCase
{
    private string $heartbeatPath = 'framework/scheduler.heartbeat';

    protected function tearDown(): void
    {
        // Clean up the heartbeat file after each test to prevent cross-worker contamination
        // in parallel test runs (tests run across 16 workers with shared storage/)
        if (file_exists(storage_path($this->heartbeatPath))) {
            unlink(storage_path($this->heartbeatPath));
        }
        parent::tearDown();
    }

    public function test_scheduler_heartbeat_writes_timestamp_to_storage_framework(): void
    {
        $path = storage_path($this->heartbeatPath);

        // Ensure clean state
        if (file_exists($path)) {
            unlink($path);
        }
        $this->assertFalse(file_exists($path), 'heartbeat file should not exist initially');

        // Run the scheduler:heartbeat command
        $this->artisan('scheduler:heartbeat')->assertSuccessful();

        // Contract 1: file exists at exact expected path
        $this->assertFileExists($path, 'heartbeat file must be written to storage/framework/scheduler.heartbeat');

        // Contract 2: file contains a parseable timestamp (not empty, not stale)
        $content = file_get_contents($path);
        $this->assertNotEmpty($content, 'heartbeat file must contain a timestamp');
        $timestamp = (int) mb_trim($content);
        $this->assertGreaterThan(0, $timestamp, 'heartbeat content must be a valid Unix timestamp');

        // Contract 3: timestamp is recent (within last 2 seconds of "now")
        $now = time();
        $this->assertLessThanOrEqual(2, $now - $timestamp, 'heartbeat timestamp must be current (within 2s)');
    }

    public function test_scheduler_heartbeat_refreshes_existing_file(): void
    {
        $path = storage_path($this->heartbeatPath);

        // Set up: write a stale timestamp
        $oldTimestamp = (int) (time() - 300); // 5 minutes ago
        file_put_contents($path, (string) $oldTimestamp);
        $this->assertFileExists($path);

        // Run the heartbeat command
        $this->artisan('scheduler:heartbeat')->assertSuccessful();

        // Contract: file is refreshed, not skipped
        $content = file_get_contents($path);
        $newTimestamp = (int) mb_trim($content);
        $this->assertGreaterThan($oldTimestamp, $newTimestamp, 'heartbeat must refresh the file, not skip it');
        $this->assertLessThanOrEqual(2, time() - $newTimestamp, 'refreshed timestamp must be current');
    }
}
