<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * A Blade view that calls route('something') for a route that does not exist
 * throws the moment it is rendered, so a renamed or deleted route becomes a
 * 500 on a live screen rather than a failed test elsewhere. This walks every
 * view and checks each reference against the route table.
 */
class RouteReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_route_reference_in_a_view_resolves(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->all();

        $missing = [];

        foreach ($this->bladeFiles() as $file) {
            preg_match_all('/route\(\s*\'([^\']+)\'/', file_get_contents($file), $matches);

            foreach (array_unique($matches[1]) as $name) {
                if (! in_array($name, $registered, true)) {
                    $missing[] = $name.' (in '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).')';
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Views reference routes that do not exist: '.implode(', ', $missing),
        );
    }

    public function test_no_view_contains_a_placeholder_link(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            if (str_contains((string) file_get_contents($file), 'href="#"')) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame([], $offenders, 'Views still contain placeholder links: '.implode(', ', $offenders));
    }

    /**
     * @return array<int, string>
     */
    private function bladeFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views'))
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
