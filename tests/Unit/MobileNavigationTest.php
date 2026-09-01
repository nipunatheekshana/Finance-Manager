<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A screen that exists in the router but in no menu is invisible on a phone,
 * where the sidebar is hidden. That is a real bug and an easy one to
 * reintroduce, so the navigation is checked against the router.
 */
class MobileNavigationTest extends TestCase
{
    private function root(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    /** Top-level screens under the authenticated app shell. */
    private function shellRoutePaths(): array
    {
        $router = file_get_contents($this->root('resources/js/router/index.ts'));

        $shell = substr(
            $router,
            strpos($router, "import('@/components/layout/AppShell.vue')"),
        );
        $shell = substr($shell, 0, strpos($shell, 'pathMatch'));

        preg_match_all("/path: '([^']*)'/", $shell, $matches);

        // Detail screens are reached from their list, and settings subpages
        // from the Settings screen itself; neither belongs in a global menu.
        $paths = array_filter(
            $matches[1],
            fn (string $path) => ! str_contains($path, ':') && ! str_contains($path, '/'),
        );

        return array_values(array_unique(array_map(fn (string $path) => '/'.$path, $paths)));
    }

    /**
     * A screen counts as reachable whether it is a row in a menu list or a
     * link written straight into the markup — the profile is opened by
     * tapping your own name, not by a list item.
     */
    private function linksTo(string $source, string $path): bool
    {
        return str_contains($source, "to: '{$path}'")
            || str_contains($source, "to=\"{$path}\"");
    }

    private function mobileNav(): string
    {
        return file_get_contents($this->root('resources/js/components/layout/BottomNav.vue'))
            .file_get_contents($this->root('resources/js/components/layout/MoreSheet.vue'));
    }

    #[Test]
    public function it_reads_every_top_level_screen_out_of_the_router(): void
    {
        $paths = $this->shellRoutePaths();

        foreach (['/', '/budget', '/plan', '/expenses', '/income', '/debts',
            '/savings', '/reports', '/calendar', '/cash-flow', '/settings'] as $expected) {
            $this->assertContains($expected, $paths);
        }
    }

    #[Test]
    public function every_screen_is_reachable_from_the_mobile_navigation(): void
    {
        $source = $this->mobileNav();

        foreach ($this->shellRoutePaths() as $path) {
            $this->assertTrue(
                $this->linksTo($source, $path),
                "{$path} is in the router but in no mobile menu, so a phone cannot reach it.",
            );
        }
    }

    #[Test]
    public function every_screen_is_reachable_from_the_desktop_sidebar(): void
    {
        $sidebar = file_get_contents($this->root('resources/js/components/layout/AppSidebar.vue'));

        // No exemptions: an account with an active plan and no allowances had
        // no link to the planner anywhere on desktop, because every route to
        // it was conditional on something that account did not have.
        foreach ($this->shellRoutePaths() as $path) {
            $this->assertTrue(
                $this->linksTo($sidebar, $path),
                "{$path} is in the router but nowhere in the sidebar.",
            );
        }
    }

    #[Test]
    public function a_phone_can_sign_out(): void
    {
        $this->assertStringContainsString('signOut', $this->mobileNav());
    }
}
