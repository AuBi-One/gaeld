<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Regression guard: every hard-coded link on the dashboard points to a
 * registered GET route (the "close the fiscal year" button once linked to
 * a path that did not exist and returned 404).
 */
class DashboardLinkRoutesTest extends TestCase
{
    public function test_dashboard_links_resolve_to_get_routes(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/Dashboard.vue'));

        preg_match_all('/href="`?(\/[^"`]*)`?"/', $source, $matches);
        $paths = array_unique($matches[1]);

        $this->assertNotEmpty(array_filter($paths, fn (string $p) => str_starts_with($p, '/accounting/year-end-closing?')));

        foreach ($paths as $path) {
            // Template placeholders such as ${id} become a UUID-shaped dummy value.
            $url = (string) preg_replace('/\$\{[^}]*\}/', '00000000-0000-0000-0000-000000000000', $path);

            try {
                Route::getRoutes()->match(Request::create($url, 'GET'));
            } catch (NotFoundHttpException) {
                $this->fail("Dashboard link {$path} matches no GET route.");
            }
        }
    }
}
