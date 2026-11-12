<?php

namespace Tests\Unit;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Guards the hand-kept test-path list of .github/workflows/ci.yml against
 * drift: every test directory on disk must be run by exactly one CI matrix
 * group, and every configured path must exist and hold PHPUnit tests (an
 * empty path makes PHPUnit report "no tests executed" and the job a false
 * green). The expectation is read from disk, so adding a test directory is
 * the only thing to remember: this test then insists CI runs it.
 *
 * Expected paths: every child of tests/Feature (directory or *Test.php), every
 * other tests/* directory holding *Test.php files, and every public plugin's
 * tests directory.
 *
 * Ported from Ideall-Engineering/Gaeld a386ce68.
 */
class CiWorkflowCoverageTest extends TestCase
{
    /** EE tests ship with phpunit.ee.xml and never run in the public CE workflow. */
    private const PRIVATE_PLUGINS = ['gaeld-ee'];

    public function test_every_test_directory_on_disk_runs_in_the_ci_matrix(): void
    {
        $missing = array_values(array_diff($this->expectedTestPaths(), $this->configuredTestPaths()));

        $this->assertSame([], $missing, 'These paths hold tests but no CI matrix group runs them. '
            .'Add them to a test-paths group in .github/workflows/ci.yml.');
    }

    public function test_every_configured_path_exists_and_holds_tests(): void
    {
        foreach ($this->configuredTestPaths() as $path) {
            $this->assertFileExists(base_path($path), "The CI matrix runs {$path}, which does not exist.");
            $this->assertNotSame([], $this->findTestFiles(base_path($path)), "The CI matrix runs {$path}, which holds no PHPUnit tests.");
        }
    }

    public function test_no_path_runs_in_more_than_one_group(): void
    {
        $configured = $this->configuredTestPaths();

        $this->assertSame(array_values(array_unique($configured)), $configured, 'A test path appears in more than one CI matrix group.');
    }

    /** @return list<string> */
    private function configuredTestPaths(): array
    {
        /** @var array{jobs: array{test: array{strategy: array{matrix: array{include: list<array{name: string, 'test-paths': string}>}}}}} $workflow */
        $workflow = Yaml::parseFile(base_path('.github/workflows/ci.yml'));

        $paths = [];
        foreach ($workflow['jobs']['test']['strategy']['matrix']['include'] as $group) {
            foreach (preg_split('/\s+/', trim($group['test-paths'])) ?: [] as $path) {
                if ($path !== '') {
                    $paths[] = rtrim($path, '/');
                }
            }
        }

        return $paths;
    }

    /** @return list<string> */
    private function expectedTestPaths(): array
    {
        $expected = [];

        foreach ($this->childrenOf(base_path('tests/Feature')) as $child) {
            if ((is_dir($child) && $this->findTestFiles($child) !== []) || str_ends_with($child, 'Test.php')) {
                $expected[] = $this->relative($child);
            }
        }

        foreach ($this->childrenOf(base_path('tests')) as $child) {
            if (is_dir($child) && basename($child) !== 'Feature' && $this->findTestFiles($child) !== []) {
                $expected[] = $this->relative($child);
            }
        }

        foreach ($this->childrenOf(base_path('plugins')) as $plugin) {
            if (is_dir($plugin.'/tests') && ! in_array(basename($plugin), self::PRIVATE_PLUGINS, true)
                && $this->findTestFiles($plugin.'/tests') !== []) {
                $expected[] = $this->relative($plugin.'/tests');
            }
        }

        sort($expected);

        return $expected;
    }

    /** @return list<string> */
    private function childrenOf(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $entry): string => $directory.'/'.$entry,
            array_diff(scandir($directory) ?: [], ['.', '..']),
        ));
    }

    /** @return list<string> Every *Test.php at or below the path */
    private function findTestFiles(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, 'Test.php') ? [$path] : [];
        }

        $found = [];
        foreach ($this->childrenOf($path) as $child) {
            $found = [...$found, ...$this->findTestFiles($child)];
        }

        return $found;
    }

    private function relative(string $absolute): string
    {
        return ltrim(substr($absolute, strlen(base_path())), '/');
    }
}
