<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One family across the product.
 *
 * There were two: DM Sans for prose and IBM Plex Mono for figures. The mono
 * was a good idea — identifiers should read as identifiers, and digits line up
 * in a column — but it was applied so widely that the product read as two
 * fonts rather than one, and a second family is a second thing to load, keep
 * in step and pay for.
 *
 * So: one family, DM Sans, with tabular figures. A figure still lines up in a
 * column, because tabular figures are a property of the numerals, not of a
 * different typeface.
 */
class OneFontFamilyTest extends TestCase
{
    /**
     * @return array<int, array{string}>
     */
    public static function stylesheetProvider(): array
    {
        return [
            ['resources/css/app.css'],
            ['resources/css/opoku-ui.css'],
            ['resources/css/portal.css'],
            ['resources/css/filament/theme.css'],
        ];
    }

    #[DataProvider('stylesheetProvider')]
    public function test_a_stylesheet_names_no_family_but_dm_sans(string $stylesheet): void
    {
        $css = (string) file_get_contents(base_path($stylesheet));

        // Every family named in a font-family declaration, quoted or not.
        preg_match_all('/font-family:\s*([^;]+);/', $css, $matches);

        foreach ($matches[1] as $declaration) {
            preg_match_all("/'([^']+)'|\"([^\"]+)\"/", $declaration, $named);

            foreach ($named[1] as $family) {
                $this->assertSame(
                    'DM Sans',
                    $family,
                    "{$stylesheet} names {$family}. The product uses one family.",
                );
            }
        }
    }

    public function test_the_second_family_is_gone_from_the_repository(): void
    {
        $this->assertFileDoesNotExist(public_path('fonts/IBMPlexMono-400.woff2'));
        $this->assertDirectoryDoesNotExist(public_path('fonts/filament/ibm-plex-mono'));

        $this->assertStringNotContainsString(
            'IBM Plex',
            (string) file_get_contents(base_path('resources/css/app.css')),
        );
    }

    public function test_figures_still_line_up_in_a_column(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/opoku-ui.css'));

        // Tabular figures are what the mono was for. Without them the columns
        // would wobble, so they have to survive the change.
        $this->assertStringContainsString('font-variant-numeric: tabular-nums', $css);
    }

    /**
     * @return array<int, array{string}>
     */
    public static function tokenStylesheetProvider(): array
    {
        return [
            ['resources/css/app.css'],
            ['resources/css/opoku-ui.css'],
        ];
    }

    #[DataProvider('tokenStylesheetProvider')]
    public function test_the_mono_token_resolves_to_the_one_family(string $stylesheet): void
    {
        $css = (string) file_get_contents(base_path($stylesheet));

        // Tailwind's default for --font-mono is the operating system's
        // monospace stack, so a `font-mono` class rendered in Menlo: a second
        // typeface the product never shipped, and the one that was left
        // behind the last time.
        $this->assertStringContainsString(
            '--font-mono: var(--font-sans)',
            $css,
            "{$stylesheet} lets --font-mono fall back to the system monospace stack.",
        );
    }

    public function test_the_console_font_survives_a_fresh_clone(): void
    {
        $stylesheet = public_path('fonts/dm-sans/index.css');

        $this->assertFileExists($stylesheet);

        $css = (string) file_get_contents($stylesheet);

        $this->assertSame(1, preg_match("/url\('([^']+)'\)/", $css, $matches));

        // The file the console asks for has to be one that is in the
        // repository. public/fonts/filament is git-ignored, so a font kept
        // there exists on one machine and nowhere else - and the console
        // falls back to the system sans without saying so.
        $this->assertFileExists(public_path('fonts/dm-sans/'.$matches[1]));
    }
}
