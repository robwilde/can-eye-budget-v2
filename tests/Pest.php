<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Run the real `docker/entrypoint.sh` in a throwaway directory with the named
 * binaries stubbed onto PATH, and report what happened. The script is executed
 * rather than reproduced so a test cannot drift from the file it pins.
 *
 * Every run gets its own directory, so the `storage/` and `bootstrap/cache`
 * trees the script creates cannot collide under `--parallel`.
 *
 * @param  array<string, string>  $stubs  binary name => sh body written after `#!/bin/sh`
 * @param  array<string, string>  $env  the whole environment; PATH is prepended automatically
 * @param  list<string>  $arguments  argv handed to the script
 * @param  array<string, string>  $files  relative path => contents, written into the working directory first
 * @return array{status: int, stdout: list<string>, stderr: string}
 */
function runEntrypoint(array $stubs, array $env = [], array $arguments = [], array $files = []): array
{
    $script = dirname(__DIR__).'/docker/entrypoint.sh';

    $base = sys_get_temp_dir().'/entrypoint-'.getmypid().'-'.bin2hex(random_bytes(8));
    $work = $base.'/work';

    mkdir($base.'/bin', 0o755, true);
    mkdir($work, 0o755, true);

    foreach ($stubs as $name => $body) {
        file_put_contents($base.'/bin/'.$name, "#!/bin/sh\n".$body."\n");
        chmod($base.'/bin/'.$name, 0o755);
    }

    foreach ($files as $path => $contents) {
        file_put_contents($work.'/'.$path, $contents);
    }

    $assignments = 'PATH='.escapeshellarg($base.'/bin:/usr/bin:/bin');

    foreach ($env as $name => $value) {
        $assignments .= ' '.$name.'='.escapeshellarg($value);
    }

    $command = 'cd '.escapeshellarg($work)
        .' && env -i '.$assignments
        .' sh '.escapeshellarg($script);

    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $command .= ' 2>'.escapeshellarg($base.'/stderr');

    $stdout = [];
    $status = 0;
    exec($command, $stdout, $status);

    $stderr = (string) file_get_contents($base.'/stderr');

    deleteEntrypointFixture($base);

    return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
}

function deleteEntrypointFixture(string $path): void
{
    if (! is_dir($path)) {
        @unlink($path);

        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            deleteEntrypointFixture($path.'/'.$entry);
        }
    }

    @rmdir($path);
}
