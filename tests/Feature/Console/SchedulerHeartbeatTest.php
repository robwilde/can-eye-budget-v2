<?php

declare(strict_types=1);

use Tests\TestCase;

final class SchedulerHeartbeatTest extends TestCase
{
    private string $tempStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate this test's storage from other parallel workers and the developer's machine.
        // Each test gets a unique temp storage root so storage_path('framework/scheduler.heartbeat')
        // resolves to an isolated location. This prevents tearDown() races and machine interference.
        $this->tempStoragePath = sys_get_temp_dir().'/ceb-heartbeat-'.bin2hex(random_bytes(8));
        mkdir($this->tempStoragePath, 0755, true);
        mkdir($this->tempStoragePath.'/framework', 0755, true);
        $this->app->useStoragePath($this->tempStoragePath);
    }

    protected function tearDown(): void
    {
        // Clean up the isolated temp storage directory (not a shared path).
        // Safe because this is the test's unique directory, not a shared resource.
        if (is_dir($this->tempStoragePath)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempStoragePath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    rmdir($fileinfo->getRealPath());
                } else {
                    unlink($fileinfo->getRealPath());
                }
            }
            rmdir($this->tempStoragePath);
        }
        parent::tearDown();
    }

    public function test_scheduler_heartbeat_writes_timestamp_to_storage_framework(): void
    {
        $path = storage_path('framework/scheduler.heartbeat');

        // Path is isolated per test; safe to assert initial state
        $this->assertFalse(file_exists($path), 'heartbeat file should not exist initially in isolated storage');

        // Run the scheduler:heartbeat command in the isolated storage context
        $this->artisan('scheduler:heartbeat')->assertSuccessful();

        // Contract 1: file exists at exact expected path (in the isolated storage)
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
        $path = storage_path('framework/scheduler.heartbeat');

        // Path is isolated per test; safe to write stale timestamp
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
