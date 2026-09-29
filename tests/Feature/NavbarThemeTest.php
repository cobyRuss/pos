<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavbarThemeTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return (string) file_get_contents(public_path('css/app.css'));
    }

    /**
     * The stylesheet with comments removed, so documentation text is never
     * mistaken for a live declaration.
     */
    private function cssRules(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->css());
    }

    public function test_the_shell_is_tagged_with_the_signed_in_role(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-role="admin"', false);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('data-role="staff"', false);
    }

    public function test_each_role_gets_its_own_sidebar_palette(): void
    {
        $css = $this->css();

        // A default (admin) block and a staff override keyed off data-role.
        $this->assertMatchesRegularExpression('/\.app-shell\s*\{[^}]*--pos-accent:\s*#\w+;/s', $css);
        $this->assertMatchesRegularExpression('/\.app-shell\[data-role="staff"\]\s*\{[^}]*--pos-accent:\s*#\w+;/s', $css);

        // The two accents must actually differ, otherwise the roles look alike.
        preg_match('/\.app-shell\s*\{[^}]*--pos-accent:\s*(#\w+);/s', $css, $admin);
        preg_match('/\[data-role="staff"\]\s*\{[^}]*--pos-accent:\s*(#\w+);/s', $css, $staff);

        $this->assertNotSame($admin[1] ?? null, $staff[1] ?? null, 'Admin and staff accents must be distinguishable.');
    }

    public function test_the_stylesheet_only_uses_variables_that_are_defined(): void
    {
        $css = $this->css();

        preg_match_all('/var\((--pos-[a-z-]+)\)/', $css, $used);
        preg_match_all('/^\s*(--pos-[a-z-]+):/m', $css, $defined);

        $undefined = array_diff(array_unique($used[1]), array_unique($defined[1]));

        $this->assertSame([], array_values($undefined), 'Every --pos-* variable used in the CSS must be defined.');
    }

    public function test_sidebar_text_meets_contrast_requirements(): void
    {
        // The old muted tone (#a3b1c2) was only ever readable on a dark surface.
        // The sidebar is light now, so it is pinned to dark slate ink instead.
        $this->assertStringNotContainsString('#64748b', $this->cssRules());
        $this->assertMatchesRegularExpression('/--pos-nav-muted:\s*#475569;/', $this->cssRules());

        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrast('#475569', '#f8fafc'),
            'Section headers on the admin sidebar must be readable.'
        );
        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrast('#475569', '#f0fdfa'),
            'Section headers on the staff sidebar must be readable.'
        );
    }

    public function test_light_surface_badges_stay_dark_inked(): void
    {
        $css = $this->cssRules();

        // product-thumb sits on a light chip, so it must not use the sidebar's
        // light muted tone.
        $this->assertStringContainsString('#475569', $css);
        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#475569', '#eef2f7'));
    }

    public function test_the_role_badge_uses_the_accent(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\.role-badge\s*\{[^}]*var\(--pos-accent\)/s', $css);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('role-badge', false)
            ->assertSee('Administrator', false);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('role-badge', false);
    }

    public function test_the_navbar_is_legible_even_if_the_stylesheet_fails_to_load(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        // The critical rules must be inlined, and must come *before* app.css so
        // the real stylesheet still wins when it is available.
        $this->assertMatchesRegularExpression(
            '#<style>.*\.app-sidebar\s*\{[^}]*background-color[^}]*\}.*</style>\s*<link[^>]+app\.css#s',
            $html,
            'Critical navbar CSS must be inlined ahead of app.css.'
        );

        // Every piece of navbar text carries its own colour rather than relying
        // on inheritance, because the sidebar is dark and text is light.
        $critical = $this->criticalCss($html);

        foreach ([
            '.app-sidebar' => 'background-color',
            '.sidebar-nav .sidebar-link' => 'color',
            '.sidebar-nav .sidebar-link.active' => 'color',
            '.sidebar-header' => 'color',
            '.avatar' => 'color',
            '.role-badge' => 'color',
        ] as $selector => $property) {
            $this->assertTrue(
                $this->ruleDeclares($critical, $selector, $property),
                "{$selector} must declare {$property} in the critical CSS."
            );
        }

        // Dark ink on a light surface, not the reverse.
        $this->assertMatchesRegularExpression('/\.app-sidebar\s*\{[^}]*background-color:\s*var\(--pos-sidebar-bg,\s*#f8fafc\)\s*!important/s', $critical);
        $this->assertMatchesRegularExpression('/\.sidebar-nav \.sidebar-link\s*\{[^}]*color:\s*var\(--pos-nav-text,\s*#0f172a\)/s', $critical);
        // Selector lists are allowed: the header shares a rule with the profile role.
        $this->assertMatchesRegularExpression('/\.sidebar-header[^{]*\{[^}]*color:\s*var\(--pos-nav-muted,\s*#475569\)/s', $critical);
    }

    public function test_the_role_chip_and_brand_are_not_white_on_white(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $critical = $this->criticalCss($html);
        $css = $this->cssRules();

        // The brand used to carry Bootstrap's text-white, which is unreadable on
        // the light sidebar. It now takes the sidebar ink token. The anchor
        // carries extra layout classes for the logo, so this matches on the
        // brand class rather than pinning the whole attribute.
        $this->assertMatchesRegularExpression(
            '/class="[^"]*\bsidebar-brand\b[^"]*"/',
            $html,
        );
        $this->assertStringNotContainsString('text-decoration-none text-white', $html);
        $this->assertTrue(
            $this->ruleDeclares($css, '.app-sidebar .sidebar-brand', 'color'),
            'The brand must be inked from the sidebar text token.'
        );
        $this->assertMatchesRegularExpression(
            '/\.app-sidebar \.offcanvas-header\s*\{[^}]*background-color:\s*var\(--pos-sidebar-bg,\s*#f8fafc\)\s*!important/s',
            $critical,
            'The sidebar header must paint the surface behind the brand.'
        );

        // The role chip lost its text-bg-* class when it was themed, so it must
        // get both a background and white text from the critical CSS. White is
        // correct here: it sits on the accent, not on the sidebar.
        $this->assertStringContainsString('role-badge', $html);
        $this->assertStringNotContainsString('text-bg-primary', $html);
        $this->assertMatchesRegularExpression('/\.role-badge\s*\{[^}]*color:\s*#fff\s*!important/s', $critical);
    }

    /**
     * The inlined critical stylesheet from a rendered page.
     */
    private function criticalCss(string $html): string
    {
        preg_match('#<style>(.*?)</style>#s', $html, $m);

        return $m[1] ?? '';
    }

    /**
     * Whether a rule for the selector declares the given property.
     */
    private function ruleDeclares(string $css, string $selector, string $property): bool
    {
        $quoted = preg_quote($selector, '#');

        return (bool) preg_match(
            '#'.str_replace('\\\\', '\\\\', $quoted).'\s*(?:,[^{]*)?\{[^}]*'.preg_quote($property, '#').'\s*:#s',
            $css
        );
    }

    public function test_the_ui_font_is_loaded_and_applied_to_the_sidebar(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('fonts.googleapis.com/css2?family=Inter', $html);
        $this->assertStringContainsString('rel="preconnect" href="https://fonts.gstatic.com"', $html);

        $css = $this->cssRules();
        $critical = $this->criticalCss($html);

        // Declared once as a token, then applied to the body and the sidebar.
        $this->assertMatchesRegularExpression('/--pos-font-sans:\s*"Inter"/', $css);
        $this->assertTrue($this->ruleDeclares($css, 'body', 'font-family'));
        $this->assertTrue($this->ruleDeclares($css, '.app-sidebar', 'font-family'));
        $this->assertStringContainsString('font-family: var(--pos-font-sans)', $css);

        // The sidebar must stay in the UI font even if app.css is unavailable.
        $this->assertStringContainsString('"Inter", system-ui', $critical);
    }

    public function test_inactive_nav_items_use_a_solid_readable_colour(): void
    {
        $css = $this->cssRules();

        // The sidebar is a light surface, so the nav ink is dark. The pale
        // greys that used to sit here are what made the labels invisible.
        $this->assertMatchesRegularExpression('/--pos-nav-text:\s*#0f172a;/', $css);
        $this->assertMatchesRegularExpression('/--pos-nav-muted:\s*#475569;/', $css);
        $this->assertStringNotContainsString('#cbd5e1', $css);
        $this->assertStringNotContainsString('#a3b1c2', $css);

        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#0f172a', '#f8fafc'), 'Admin nav text');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#0f172a', '#f0fdfa'), 'Staff nav text');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#475569', '#f8fafc'), 'Admin section header');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#475569', '#f0fdfa'), 'Staff section header');

        // The active pill is the one place white text is correct, because it
        // sits on the accent rather than on the sidebar surface.
        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#ffffff', '#4f46e5'), 'Admin active pill');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#ffffff', '#0f766e'), 'Staff active pill');
    }

    public function test_the_sidebar_surface_cannot_be_overridden_by_the_offcanvas_utility(): void
    {
        // Bootstrap ships, inside @media (min-width: 992px):
        //   .offcanvas-lg { background-color: transparent !important }
        //   .offcanvas-lg .offcanvas-header { display: none }
        // The sidebar root carries offcanvas-lg, and the CDN loads first, so our
        // rules must be !important too or the surface never paints and the
        // header vanishes. Only !important beats !important.
        $css = $this->cssRules();

        $surface = $this->ruleBodies($css, '.app-sidebar');

        $this->assertMatchesRegularExpression(
            '/background-color:\s*var\(--pos-sidebar-bg\)\s*!important\s*;/',
            $surface,
            'The sidebar background must be !important to beat .offcanvas-lg\'s transparent !important.'
        );

        $header = $this->ruleBodies($css, '.app-sidebar .offcanvas-header');

        $this->assertMatchesRegularExpression(
            '/display:\s*flex\s*!important\s*;/',
            $header,
            'The store-name band must be !important to beat .offcanvas-lg .offcanvas-header { display: none }.'
        );
        $this->assertMatchesRegularExpression(
            '/background-color:\s*var\(--pos-sidebar-bg\)\s*!important\s*;/',
            $header
        );

        // The inlined critical copy has the same obligation.
        $critical = $this->criticalCss(
            $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))->getContent()
        );

        $this->assertMatchesRegularExpression(
            '/background-color:[^;}]*!important\s*;/',
            $this->ruleBodies($critical, '.app-sidebar'),
            'The critical CSS sidebar background must also be !important.'
        );

        $this->assertMatchesRegularExpression(
            '/display:\s*flex\s*!important\s*;/',
            $this->ruleBodies($critical, '.app-sidebar .offcanvas-header')
        );
    }

    /**
     * The concatenated bodies of every rule whose selector list contains the
     * given selector.
     *
     * A selector can appear in several rules - the sidebar carries both a font
     * declaration and a surface declaration - so a single regex match on the
     * first occurrence is not enough.
     */
    private function ruleBodies(string $css, string $selector): string
    {
        $quoted = preg_quote($selector, '#');
        $bodies = [];

        // The boundary is a brace, a semicolon or a line break: hand-written CSS
        // in the layout separates some rules with a blank line rather than the
        // closing brace of the previous one.
        preg_match_all('#(?:^|[},;\n])\s*[^{}]*'.$quoted.'\s*(?:,[^{]*)?\{([^}]*)\}#s', $css, $matches);

        foreach ($matches[1] as $body) {
            $bodies[] = $body;
        }

        return implode("\n", $bodies);
    }

    public function test_the_critical_fallbacks_match_the_stylesheet_tokens(): void
    {
        $css = $this->cssRules();
        $critical = $this->criticalCss(
            $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))->getContent()
        );

        // The values app.css declares for the default (admin) palette.
        preg_match_all('/--pos-([a-z-]+):\s*(#[0-9a-f]{3,8})\s*;/', $css, $declared, PREG_SET_ORDER);
        $tokens = [];

        foreach ($declared as [, $name, $hex]) {
            // The first declaration wins: :root holds the shared inks, .app-shell
            // holds the default-role surface, and the staff block comes last.
            $tokens[$name] ??= $hex;
        }

        // Every literal fallback in the critical block must be the same colour
        // the real stylesheet uses, or the navbar changes hue when app.css loads.
        preg_match_all('/var\(--pos-([a-z-]+),\s*(#[0-9a-f]{3,8})\s*\)/', $critical, $fallbacks, PREG_SET_ORDER);

        $this->assertNotEmpty($fallbacks, 'The critical CSS should carry literal fallbacks.');

        foreach ($fallbacks as [$match, $name, $hex]) {
            $this->assertArrayHasKey($name, $tokens, "app.css must declare --pos-{$name}.");

            $this->assertSame(
                strtolower($tokens[$name]),
                strtolower($hex),
                "Critical CSS fallback for --pos-{$name} (#{$hex}) has drifted from app.css ({$tokens[$name]})."
            );
        }
    }

    public function test_icons_follow_the_state_of_their_link(): void
    {
        $css = $this->cssRules();
        $critical = $this->criticalCss(
            $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))->getContent()
        );

        // Dim while inactive, full strength on hover and active.
        $this->assertTrue($this->ruleDeclares($css, '.sidebar-nav .sidebar-link i', 'color'));
        $this->assertMatchesRegularExpression(
            '/\.sidebar-nav \.sidebar-link i\s*\{[^}]*color:\s*var\(--pos-nav-muted\)/s',
            $css
        );
        $this->assertMatchesRegularExpression('/\.sidebar-nav \.sidebar-link:hover i\s*\{[^}]*color:\s*var\(--pos-nav-hover-text\)/s', $css);
        $this->assertMatchesRegularExpression('/\.sidebar-nav \.sidebar-link\.active i\s*\{[^}]*color:\s*#fff/s', $css);

        // And the same in the critical CSS, so icons do not vanish either.
        $this->assertMatchesRegularExpression('/\.sidebar-nav \.sidebar-link i\s*\{[^}]*color:\s*var\(--pos-nav-muted,\s*#475569\)/s', $critical);
        $this->assertMatchesRegularExpression('/\.sidebar-nav \.sidebar-link:hover i\s*\{[^}]*color:\s*var\(--pos-nav-hover-text,\s*#0f172a\)/s', $critical);
        $this->assertMatchesRegularExpression('/\.sidebar-nav \.sidebar-link\.active i[^{]*\{[^}]*color:\s*#fff/s', $critical);
    }

    public function test_the_section_header_is_tracked_out_and_readable(): void
    {
        $css = $this->cssRules();

        preg_match('/\.sidebar-header\s*\{([^}]*)\}/s', $css, $m);
        $rule = $m[1] ?? '';

        $this->assertStringContainsString('text-transform: uppercase', $rule);
        $this->assertStringContainsString('font-weight: 700', $rule);
        $this->assertMatchesRegularExpression('/letter-spacing:\s*0\.1[0-9]em/', $rule);
        $this->assertStringContainsString('var(--pos-nav-muted)', $rule);
        $this->assertStringNotContainsString('0.09em', $rule);
    }

    public function test_the_profile_block_sits_at_the_top_of_the_sidebar(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create(['name' => 'Store Administrator']))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $sidebar = $this->sidebarMarkup($html);

        $this->assertSame(1, substr_count($sidebar, 'sidebar-profile'), 'The profile block must appear exactly once.');

        // Above the nav list, and the old bottom block is gone.
        $this->assertLessThan(
            strpos($sidebar, 'sidebar-nav'),
            strpos($sidebar, 'sidebar-profile'),
            'The profile block must come before the navigation list.'
        );
        $this->assertStringNotContainsString('mt-auto p-3 border-top border-secondary-subtle', $sidebar);
        $this->assertStringContainsString('profile-name', $sidebar);
        $this->assertStringContainsString('profile-role', $sidebar);
    }

    public function test_the_profile_role_label_is_not_bootstrap_grey(): void
    {
        $html = $this->actingAs(User::factory()->staff()->create())
            ->get(route('pos.index'))
            ->assertOk()
            ->getContent();

        // Bootstrap's grey text utilities are not used anywhere in the sidebar;
        // the name and role are both inked from the sidebar tokens.
        $this->assertStringNotContainsString('text-secondary', $this->sidebarMarkup($html));

        $css = $this->cssRules();
        $this->assertMatchesRegularExpression('/\.sidebar-profile \.profile-name\s*\{[^}]*color:\s*var\(--pos-sidebar-text\)/s', $css);
        $this->assertMatchesRegularExpression('/\.sidebar-profile \.profile-role\s*\{[^}]*color:\s*var\(--pos-nav-muted\)/s', $css);
    }

    /**
     * Just the sidebar partial's output from a rendered page.
     */
    private function sidebarMarkup(string $html): string
    {
        preg_match('#<div class="offcanvas-lg.*?<main#s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_the_till_page_still_renders_its_product_grid(): void
    {
        $this->actingAs(User::factory()->staff()->create());
        Product::factory()->create(['name' => 'Themed Item']);

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('pos-grid', false)
            ->assertSee('Themed Item');
    }

    /**
     * WCAG relative-contrast ratio between two hex colours.
     */
    private function contrast(string $foreground, string $background): float
    {
        $luminance = function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channels = [];

            foreach ([0, 2, 4] as $i) {
                $value = hexdec(substr($hex, $i, 2)) / 255;
                $channels[] = $value <= 0.03928
                    ? $value / 12.92
                    : (($value + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $a = $luminance($foreground);
        $b = $luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
