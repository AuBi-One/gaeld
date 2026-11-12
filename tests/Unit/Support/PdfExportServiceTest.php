<?php

namespace Tests\Unit\Support;

use App\Support\PdfExportService;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * PdfExportService raises the memory limit for large reports but must leave
 * PHP's execution time alone on the CLI: set_time_limit() restarts the clock
 * for the whole process, so a queue worker rendering an invoice PDF (or a test
 * run) would get 120 seconds for everything that follows. Upstream dropped the
 * call in v3.8.21; this keeps it from coming back.
 *
 * Idea from Ideall-Engineering/Gaeld e7e3744b.
 */
class PdfExportServiceTest extends TestCase
{
    private string $viewDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewDir = sys_get_temp_dir().'/pdf-export-test-'.bin2hex(random_bytes(4));
        mkdir($this->viewDir);
        file_put_contents($this->viewDir.'/pdf-export-probe.blade.php', '<html><body><p>{{ $text }}</p></body></html>');
        View::addLocation($this->viewDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->viewDir.'/pdf-export-probe.blade.php');
        @rmdir($this->viewDir);

        parent::tearDown();
    }

    public function test_rendering_a_pdf_does_not_change_the_execution_time_limit_on_the_cli(): void
    {
        $this->assertTrue(app()->runningInConsole());
        set_time_limit(0);

        (new PdfExportService)->stream('pdf-export-probe', ['text' => 'probe'], 'probe.pdf');
        (new PdfExportService)->download('pdf-export-probe', ['text' => 'probe'], 'probe.pdf');

        $this->assertSame('0', ini_get('max_execution_time'));
    }

    public function test_rendering_a_pdf_still_raises_a_low_memory_limit(): void
    {
        $before = ini_get('memory_limit');
        ini_set('memory_limit', '256M');

        try {
            (new PdfExportService)->stream('pdf-export-probe', ['text' => 'probe'], 'probe.pdf');

            $this->assertSame('512M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', (string) $before);
        }
    }
}
