<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * tests/bootstrap.php refuses to start when bootstrap/cache/config.php exists:
 * a cached config ignores phpunit.xml's environment, so the suite would run
 * against the development database and RefreshDatabase would wipe it.
 *
 * The guard is exercised on a copy of the bootstrap file in a scratch tree, so
 * the real tree never gets a config cache (which would break every later run).
 *
 * Ported from Ideall-Engineering/Gaeld 028e6b2e (the bootstrap guard only).
 */
class TestBootstrapGuardTest extends TestCase
{
    private string $tree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tree = sys_get_temp_dir().'/bootstrap-guard-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tree.'/tests');
        File::ensureDirectoryExists($this->tree.'/bootstrap/cache');
        File::ensureDirectoryExists($this->tree.'/vendor');
        File::copy(base_path('tests/bootstrap.php'), $this->tree.'/tests/bootstrap.php');
        // Stands in for Composer's autoloader: reaching it means the guard let the run through.
        File::put($this->tree.'/vendor/autoload.php', "<?php\necho 'AUTOLOAD-REACHED';\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tree);

        parent::tearDown();
    }

    public function test_it_aborts_when_the_config_is_cached(): void
    {
        File::put($this->tree.'/bootstrap/cache/config.php', "<?php return [];\n");

        $process = $this->runBootstrap();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('config:clear', $process->getErrorOutput());
        $this->assertStringNotContainsString('AUTOLOAD-REACHED', $process->getOutput());
    }

    public function test_it_starts_normally_without_a_config_cache(): void
    {
        $process = $this->runBootstrap();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('AUTOLOAD-REACHED', $process->getOutput());
    }

    private function runBootstrap(): Process
    {
        $process = new Process([PHP_BINARY, $this->tree.'/tests/bootstrap.php']);
        $process->run();

        return $process;
    }
}
