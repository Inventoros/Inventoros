<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Two small layout defects that no PHP test can reach, pinned at the source.
 *
 * There is no JS test runner in this project, and both of these are the kind
 * of thing that silently regresses on the next edit to the file.
 */
class MobileLayoutMarkupTest extends TestCase
{
    protected function source(string $path): string
    {
        return (string) file_get_contents(base_path('resources/js/'.$path));
    }

    /**
     * The mobile drawer is a fixed overlay, so without a scroll lock the page
     * keeps scrolling underneath it: a swipe meant for the nav list moves the
     * page instead, and closing the drawer leaves the reader somewhere they
     * never navigated to.
     */
    public function test_the_mobile_drawer_locks_the_page_behind_it(): void
    {
        $layout = $this->source('Layouts/AppLayout.vue');

        $this->assertStringContainsString('watch(mobileOpen', $layout);
        $this->assertStringContainsString("document.body.style.overflow = open ? 'hidden' : ''", $layout);
    }

    /**
     * A lock that is only released by closing the drawer strands the page
     * unscrollable if the component unmounts while it is open.
     */
    public function test_the_scroll_lock_is_released_on_unmount(): void
    {
        $layout = $this->source('Layouts/AppLayout.vue');

        $this->assertStringContainsString('onBeforeUnmount', $layout);
        $this->assertMatchesRegularExpression(
            '/onBeforeUnmount\(\(\) => \{\s*document\.body\.style\.overflow = \'\';/',
            $layout,
        );
    }

    /**
     * Every table in the app goes through DataTable, which already scrolls
     * horizontally. These two do not, and both sat in a container that clipped
     * instead -- so on a narrow viewport the right-hand columns were cut off
     * with no way to reach them.
     */
    public function test_wide_tables_scroll_rather_than_clip(): void
    {
        foreach (['Pages/Reports/Index.vue', 'Pages/WorkOrders/Create.vue'] as $path) {
            $source = $this->source($path);

            $this->assertStringContainsString(
                'overflow-x-auto',
                $source,
                "{$path} has a min-w-full table with no horizontal scroll container.",
            );
        }
    }

    /**
     * The guard for the next table that gets added by hand: if a page grows a
     * raw <table>, it needs its own scroll container, because it is not getting
     * one from DataTable.
     */
    public function test_no_page_ships_a_table_without_a_scroll_container(): void
    {
        $offenders = [];

        // Recursive on purpose: PHP's glob() does not treat ** as "any depth",
        // so a glob-based sweep would quietly skip Dashboard.vue and everything
        // nested deeper than one directory, and pass for the wrong reason.
        $pages = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/js/Pages'), \FilesystemIterator::SKIP_DOTS)
        );

        $scanned = 0;

        foreach ($pages as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }

            $scanned++;
            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, '<table') && ! str_contains($source, 'overflow-x-auto')) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertGreaterThan(50, $scanned, 'The sweep found almost no pages, so it is not actually scanning.');

        $this->assertSame([], $offenders, 'Tables with no horizontal scroll container: '.implode(', ', $offenders));
    }
}
