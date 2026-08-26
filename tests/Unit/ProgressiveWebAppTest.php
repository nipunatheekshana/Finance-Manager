<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Installability is easy to break by accident — a renamed icon, a manifest
 * field dropped in an edit — and impossible to notice without a phone in hand.
 */
class ProgressiveWebAppTest extends TestCase
{
    private function root(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    private function manifest(): array
    {
        return json_decode(file_get_contents($this->root('public/manifest.webmanifest')), true);
    }

    #[Test]
    public function the_manifest_has_everything_a_browser_needs_to_offer_an_install(): void
    {
        $manifest = $this->manifest();

        foreach (['name', 'short_name', 'start_url', 'scope', 'display', 'icons'] as $key) {
            $this->assertArrayHasKey($key, $manifest);
        }

        $this->assertSame('standalone', $manifest['display']);

        $sizes = array_column($manifest['icons'], 'sizes');
        $this->assertContains('192x192', $sizes, 'Chrome requires a 192px icon.');
        $this->assertContains('512x512', $sizes, 'Chrome requires a 512px icon.');

        // Android crops a non-maskable icon into a circle, badly.
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
    }

    #[Test]
    public function every_icon_the_manifest_names_actually_exists(): void
    {
        foreach ($this->manifest()['icons'] as $icon) {
            $this->assertFileExists($this->root('public'.$icon['src']));
        }
    }

    #[Test]
    public function the_page_head_carries_the_tags_ios_needs(): void
    {
        $head = file_get_contents($this->root('resources/views/app.blade.php'));

        $this->assertStringContainsString('rel="manifest"', $head);
        // iOS ignores the manifest entirely and reads these three instead.
        $this->assertStringContainsString('apple-touch-icon', $head);
        $this->assertStringContainsString('apple-mobile-web-app-capable', $head);
        $this->assertStringContainsString('apple-mobile-web-app-title', $head);

        preg_match('/apple-touch-icon" href="([^"]+)"/', $head, $matches);
        $this->assertFileExists($this->root('public'.$matches[1]));
    }

    #[Test]
    public function the_service_worker_answers_navigations_so_the_app_is_installable(): void
    {
        $worker = file_get_contents($this->root('resources/js/sw.ts'));

        // Chrome only offers an install when the worker can serve the page.
        $this->assertStringContainsString('NavigationRoute', $worker);
        $this->assertStringContainsString('precacheAndRoute', $worker);
    }

    #[Test]
    public function both_menus_offer_the_install(): void
    {
        $mobile = file_get_contents($this->root('resources/js/components/layout/MoreSheet.vue'));
        $desktop = file_get_contents($this->root('resources/js/components/layout/AppSidebar.vue'));

        $this->assertStringContainsString('canInstall', $mobile);
        $this->assertStringContainsString('canInstall', $desktop);
    }

    #[Test]
    public function the_install_sheet_explains_the_manual_route_for_ios(): void
    {
        $sheet = file_get_contents($this->root('resources/js/components/layout/InstallSheet.vue'));

        // Safari fires no install event, so instructions are the only option.
        $this->assertStringContainsString('Add to Home Screen', $sheet);
        $this->assertStringContainsString('isIos', $sheet);
    }
}
