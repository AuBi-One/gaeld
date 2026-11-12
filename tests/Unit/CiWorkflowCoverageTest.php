<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the hand-kept test-path list of .github/workflows/ci.yml against
 * drift: every test directory on disk must be run by exactly one CI matrix
 * group, and every configured path must exist and hold PHPUnit tests (an
 * empty path makes PHPUnit report "no tests executed" and the job a false
 * green). The expectation is read from disk, so adding a test directory is
 * the only thing to remember: this test then insists CI runs it.
 *
 * Expected paths: every child of tests/Feature (directory or *Test.php), every
 * other tests/* directory holding *Test.php files, any tests/*Test.php file,
 * and every public plugin's tests directory. A path listed together with one
 * of its parents would run twice and also fails.
 *
 * A plain PHPUnit test: it only reads files, no application needed.
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
            $this->assertFileExists(self::basePath($path), "The CI matrix runs {$path}, which does not exist.");
            $this->assertNotSame([], $this->findTestFiles(self::basePath($path)), "The CI matrix runs {$path}, which holds no PHPUnit tests.");
        }
    }

    public function test_no_path_runs_in_more_than_one_group(): void
    {
        $configured = $this->configuredTestPaths();

        $this->assertSame(array_values(array_unique($configured)), $configured, 'A test path appears in more than one CI matrix group.');
    }

    public function test_no_path_is_listed_together_with_a_parent(): void
    {
        $configured = $this->configuredTestPaths();

        $nested = [];
        foreach ($configured as $parent) {
            foreach ($configured as $child) {
                if (str_starts_with($child, $parent.'/')) {
                    $nested[] = "{$child} (inside {$parent})";
                }
            }
        }

        $this->assertSame([], $nested, 'These CI paths are also covered by a parent path and would run twice.');
    }

    /** @return list<string> */
    private function configuredTestPaths(): array
    {
        /** @var array{jobs: array{test: array{strategy: array{matrix: array{include: list<array{name: string, 'test-paths': string}>}}}}} $workflow */
        $workflow = Yaml::parseFile(self::basePath('.github/workflows/ci.yml'));

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

        foreach ($this->childrenOf(self::basePath('tests/Feature')) as $child) {
            if ((is_dir($child) && $this->findTestFiles($child) !== []) || str_ends_with($child, 'Test.php')) {
                $expected[] = $this->relative($child);
            }
        }

        foreach ($this->childrenOf(self::basePath('tests')) as $child) {
            if ((is_dir($child) && basename($child) !== 'Feature' && $this->findTestFiles($child) !== [])
                || (is_file($child) && str_ends_with($child, 'Test.php'))) {
                $expected[] = $this->relative($child);
            }
        }

        foreach ($this->childrenOf(self::basePath('plugins')) as $plugin) {
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

    private static function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path !== '' ? '/'.$path : '');
    }

    private function relative(string $absolute): string
    {
        return ltrim(substr($absolute, strlen(self::basePath())), '/');
    }
}
