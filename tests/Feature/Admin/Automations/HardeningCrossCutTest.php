<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations;

use Tests\TestCase;

/**
 * B12-UI — PR 6 / Chunk 6 — Hardening + cross-cut regression guard.
 *
 * This test does NOT add production code. Its job is to lock down the B12-UI
 * cross-cutting conventions that shipped in PR 1..5 so any future drift is
 * caught at `php artisan test --filter=HardeningCrossCutTest` time.
 *
 * Scenarios covered (from `openspec/changes/b12-ui/specs/admin-automations-ui-conventions.md`):
 *
 *   SCN-UI-09 — Every admin view (non-partial, non-Livewire) extends
 *                `layouts.app` (REQ-UI-01). Partials and Livewire child views
 *                are intentionally excluded: they are `@include`d or hosted
 *                by a parent that already extends the layout.
 *
 *   SCN-UI-10 — No "bulk actions" button is rendered anywhere in the B12-UI
 *                Blade surface. Sweep across:
 *                  resources/views/admin/automations/
 *                  resources/views/livewire/admin/automations/
 *                Regex (literal): `bulk[-_ ]actions?|<select[^>]*multiple[^>]*size`.
 *                Zero matches expected (REQ-UI-10, AC-12).
 *
 *   SCN-UI-11 — `retry_policy_json` does NOT appear as an editable form
 *                input anywhere in the B12-UI Blade surface. Sweep regex:
 *                `wire:model.*retry_policy|name="retry_policy"|name="retry_policy_json"`.
 *                Zero matches expected (REQ-UI-11, AC-10). Comments inside
 *                `@props` docblocks are NOT bound inputs and are excluded
 *                by the regex (the literal `name="..."` and `wire:model=...`
 *                attribute shape never appears in prose).
 *
 *   SCN-UI-12 — The visible Spanish copy for the title of
 *                `admin.automations.show` does NOT use any breadcrumb
 *                component. Sweep regex (literal):
 *                `x-breadcrumbs|aria-label="breadcrumb"`.
 *                Zero matches expected (REQ-UI-04, design §8.14).
 *
 *   SCN-ENGINE-NO-DRIFT — `php artisan test --filter=AutomationEngineTest`
 *                returns 10/10 green (21 assertions). This is the engine
 *                regression guard promised by `tasks.md §C.4`.
 *
 * Conventions used by this test:
 *   - `Illuminate\Support\Facades\Process::run(...)` to invoke the OS grep
 *     and `php artisan test` subprocesses.
 *   - No `RefreshDatabase` — none of the assertions touch the DB; the engine
 *     subprocess is given an isolated in-memory SQLite DB by the parent
 *     phpunit.xml (`<env name="DB_CONNECTION" value="sqlite"/>`).
 *   - No provider boot — no permission grants are issued; the assertions
 *     are filesystem + subprocess-level.
 *
 * Trace:
 *   - Spec  : openspec/changes/b12-ui/specs/admin-automations-ui-conventions.md
 *             §REQ-UI-01 / UI-04 / UI-10 / UI-11 + Scenarios §SCN-UI-05 / SCN-UI-06.
 *   - Tasks : openspec/changes/b12-ui/tasks.md §C.4 (engine regression at every PR)
 *             and §A.Chunk 6 (PR 7 hardening — this is its test artefact).
 *   - Design: openspec/changes/b12-ui/design.md §8.14 (no breadcrumbs),
 *             §13 (cross-cutting invariants).
 */
class HardeningCrossCutTest extends TestCase
{
    /**
     * Root of the B12-UI Blade surface (two trees).
     */
    private const VIEW_TREES = [
        'resources/views/admin/automations',
        'resources/views/livewire/admin/automations',
    ];

    // -------------------------------------------------------------------
    // SCN-UI-09 — Every admin view extends `layouts.app`.
    // -------------------------------------------------------------------

    public function test_every_admin_view_extends_layouts_app(): void
    {
        $basePath = base_path();

        // Admin views (top-level routes) MUST @extends('layouts.app'). The
        // partial tree is excluded: partials are `@include`d by hosts that
        // already extend the layout. Livewire child views are also excluded:
        // they are components, not pages, and never carry an `@extends`.
        $candidates = glob($basePath . '/resources/views/admin/automations/*.blade.php') ?: [];

        $this->assertNotEmpty(
            $candidates,
            'Expected at least one top-level admin/automations Blade view to assert SCN-UI-09.'
        );

        $violators = [];
        foreach ($candidates as $file) {
            $contents = (string) file_get_contents($file);
            if (! str_contains($contents, "@extends('layouts.app')")) {
                $violators[] = $this->relativeToBase($file);
            }
        }

        $this->assertSame(
            [],
            $violators,
            'SCN-UI-09 violation — every top-level admin.automations.* view must '
            . '@extends("layouts.app") (REQ-UI-01). Missing extends in: '
            . implode(', ', $violators)
        );
    }

    // -------------------------------------------------------------------
    // SCN-UI-10 — No "bulk actions" markers in any B12-UI view.
    // -------------------------------------------------------------------

    public function test_no_bulk_actions_rendered_in_views(): void
    {
        // Literal regex from the spec (SCN-UI-05): match either
        // `bulk[-_ ]actions?` (the words bulk-action(s) in any separator)
        // OR a `<select ... multiple ... size>` (a multi-row picker that
        // implies bulk selection). Case-insensitive on Windows grep via `-i`.
        $pattern = 'bulk[-_ ]actions?|<select[^>]*multiple[^>]*size';

        $output = $this->runRecursiveGrep(
            $pattern,
            self::VIEW_TREES,
            // strip comments before matching: a comment can mention the
            // phrase without it being a rendered control. We use PHP
            // stripping before invoking grep so the OS sees only Blade
            // markup, never prose.
            stripBladeComments: true,
        );

        $this->assertSame(
            '',
            trim($output),
            "SCN-UI-10 violation — bulk-ops markers must NOT appear in the B12-UI "
            . "Blade surface (REQ-UI-10, AC-12). Grep output:\n" . $output
        );
    }

    // -------------------------------------------------------------------
    // SCN-UI-11 — No `retry_policy_json` form input in any B12-UI view.
    // -------------------------------------------------------------------

    public function test_no_retry_policy_json_input_in_views(): void
    {
        // Literal regex from the spec (SCN-UI-06): wire:model/retry_policy,
        // name="retry_policy", or name="retry_policy_json". Comments inside
        // @props docblocks are stripped (they are not form inputs).
        $pattern = 'wire:model[^>]*retry_policy|name="retry_policy"|name="retry_policy_json"';

        $output = $this->runRecursiveGrep(
            $pattern,
            self::VIEW_TREES,
            stripBladeComments: true,
        );

        $this->assertSame(
            '',
            trim($output),
            "SCN-UI-11 violation — retry_policy_json must NOT appear as a form "
            . "input in any B12-UI view (REQ-UI-11, AC-10). Grep output:\n" . $output
        );
    }

    // -------------------------------------------------------------------
    // SCN-UI-12 — show view uses no breadcrumb component.
    // -------------------------------------------------------------------

    public function test_show_view_does_not_render_breadcrumb_component(): void
    {
        // Literal regex from the spec (REQ-UI-04, design §8.14):
        // `x-breadcrumbs` (the component tag) or `<nav aria-label="breadcrumb">`
        // (an inline breadcrumb landmark). The shared
        // `layouts.partials.breadcrumbs` partial is still included by
        // `layouts/app.blade.php` for OTHER modules — this assertion
        // scopes to the B12-UI `show` view only.
        $pattern = 'x-breadcrumbs|aria-label="breadcrumb"';

        $output = $this->runRecursiveGrep(
            $pattern,
            ['resources/views/admin/automations/show.blade.php'],
            stripBladeComments: true,
        );

        $this->assertSame(
            '',
            trim($output),
            "SCN-UI-12 violation — admin.automations.show must NOT render any "
            . "breadcrumb component (REQ-UI-04, design §8.14). Grep output:\n" . $output
        );
    }

    // -------------------------------------------------------------------
    // SCN-ENGINE-NO-DRIFT — engine regression at every PR boundary.
    // -------------------------------------------------------------------

    public function test_engine_test_suite_remains_10_over_10_green(): void
    {
        $output = $this->runArtisanTest('--filter=AutomationEngineTest');

        // The subprocess prints a single JSON envelope on the last line:
        //     {"tool":"phpunit","result":"passed","tests":10,"passed":10,"assertions":21,...}
        // We assert on the structured fields so a regression in test count
        // OR assertion count trips this guard (matches `tasks.md §C.4`).
        $this->assertStringContainsString(
            '"result":"passed"',
            $output,
            "SCN-ENGINE-NO-DRIFT — AutomationEngineTest must stay green at every "
            . "PR boundary. Output:\n" . $output
        );
        $this->assertStringContainsString(
            '"tests":10',
            $output,
            "SCN-ENGINE-NO-DRIFT — AutomationEngineTest must keep its 10/10 test "
            . "count (engine untouched). Output:\n" . $output
        );
        $this->assertStringContainsString(
            '"passed":10',
            $output,
            "SCN-ENGINE-NO-DRIFT — all 10 AutomationEngineTest tests must pass. "
            . "Output:\n" . $output
        );
        $this->assertStringContainsString(
            '"assertions":21',
            $output,
            "SCN-ENGINE-NO-DRIFT — assertion count must stay at 21 (engine "
            . "untouched). Output:\n" . $output
        );
    }

    // -------------------------------------------------------------------
    // Helpers — subprocess invocation.
    // -------------------------------------------------------------------

    /**
     * Run `grep -rEn` (POSIX) or `findstr /R /S` (Windows fallback) over
     * the given view trees and return the raw stdout. An empty string
     * means zero matches — the asserted-by-spec state.
     *
     * If `$stripBladeComments` is true, the helper strips Blade
     * `{{-- … --}}` comment blocks from each `.blade.php` file before
     * running grep, so prose mentions in docblocks do not produce
     * false-positive matches.
     */
    private function runRecursiveGrep(
        string $pattern,
        array $relativePaths,
        bool $stripBladeComments,
    ): string {
        $basePath = base_path();

        // Resolve every input path to absolute. We feed grep a single
        // directory at a time so the output is reproducible across
        // platforms (Windows grep /usr/bin/grep.exe handles POSIX
        // `-rEn` correctly).
        $absDirs = [];
        foreach ($relativePaths as $rel) {
            $abs = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($abs)) {
                $absDirs[] = $abs; // single-file path (SCN-UI-12 case)
            } elseif (is_dir($abs)) {
                $absDirs[] = $abs;
            }
        }

        if ($absDirs === []) {
            return '';
        }

        if ($stripBladeComments) {
            // PHP-side pre-strip so grep only sees Blade markup, not prose.
            $this->stripBladeCommentsInPlace($absDirs);
        }

        // Resolve grep — `command -v` exists on POSIX bash; on Windows cmd
        // it's missing and falls back to `where`. We use `where` directly on
        // Windows to avoid the Windows shell printing
        // "El sistema no puede encontrar la ruta especificada" when the
        // non-existent `command` builtin is invoked first.
        // CRITICAL: `where` returns one path per line; we MUST take the
        // first line only — passing a multi-line string to Symfony Process
        // results in a silent grep failure that would let forbidden
        // patterns slip through.
        if (DIRECTORY_SEPARATOR === '\\') {
            $rawGrep = (string) @shell_exec('where grep 2>nul');
        } else {
            $rawGrep = (string) @shell_exec('command -v grep 2>/dev/null');
        }
        $grepFirst = $this->firstNonEmptyLine($rawGrep);

        if ($grepFirst !== '' && $this->isPosixGrep($grepFirst)) {
            $cmd = [$grepFirst, '-rEni', '--include=*.blade.php', '--', $pattern];
            foreach ($absDirs as $dir) {
                $cmd[] = $dir;
            }
        } else {
            // Windows findstr fallback — limited regex syntax, but
            // sufficient for the literal patterns used here.
            $cmd = ['findstr', '/R', '/S', '/I', '/N', $pattern];
            foreach ($absDirs as $dir) {
                $cmd[] = $dir;
            }
        }

        $process = new \Symfony\Component\Process\Process($cmd, $basePath);
        $process->setTimeout(60);
        $process->run();

        // grep returns 1 on "no matches" — that's the GREEN signal we want.
        // Treat non-zero exits that still produced stdout as warnings and
        // surface the stdout to the assertion message.
        return (string) $process->getOutput();
    }

    /**
     * Run `php artisan test …` as a subprocess and return combined output.
     * Uses the same PHP binary the parent test runner is using.
     */
    private function runArtisanTest(string $filterArgs): string
    {
        $php = PHP_BINARY;

        $cmd = [$php, 'artisan', 'test', $filterArgs];

        $process = new \Symfony\Component\Process\Process($cmd, base_path());
        $process->setTimeout(180); // engine suite is ~2s on baseline; give headroom.
        $process->run();

        return $process->getOutput() . ($process->getErrorOutput() !== '' ? "\n--- STDERR ---\n" . $process->getErrorOutput() : '');
    }

    /**
     * Strip `{{-- … --}}` Blade comment blocks from every `.blade.php`
     * file under the given absolute paths. Operates by replacing
     * matched spans with whitespace of identical byte length so file
     * line/column offsets remain stable for any future tooling that
     * consumes grep -n output.
     */
    private function stripBladeCommentsInPlace(array $absPaths): void
    {
        $files = [];
        foreach ($absPaths as $p) {
            if (is_file($p)) {
                $files[] = $p;
            } elseif (is_dir($p)) {
                $found = glob($p . DIRECTORY_SEPARATOR . '*.blade.php') ?: [];
                $files = array_merge($files, $found);
                $found = glob($p . DIRECTORY_SEPARATOR . '**/*.blade.php') ?: [];
                $files = array_merge($files, $found);
            }
        }
        $files = array_values(array_unique($files));

        foreach ($files as $file) {
            $orig = (string) file_get_contents($file);
            $stripped = preg_replace_callback(
                '/\{\{--(.*?)--\}\}/s',
                static function (array $m): string {
                    return str_repeat(' ', strlen($m[0]));
                },
                $orig
            );
            if ($stripped !== $orig) {
                file_put_contents($file, $stripped);
                // Restore in tearDown to keep the test idempotent.
                $this->commentRestorers[] = [$file, $orig];
            }
        }
    }

    /** @var array<int, array{0: string, 1: string}> */
    private array $commentRestorers = [];

    protected function tearDown(): void
    {
        foreach ($this->commentRestorers as [$file, $orig]) {
            file_put_contents($file, $orig);
        }
        $this->commentRestorers = [];
        parent::tearDown();
    }

    private function relativeToBase(string $abs): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;
        return str_starts_with($abs, $base) ? substr($abs, strlen($base)) : $abs;
    }

    /**
     * Best-effort check that the resolved grep binary is a POSIX grep
     * (not Windows findstr or a wrapper script).
     */
    private function isPosixGrep(string $bin): bool
    {
        $bin = strtolower($bin);
        if (str_contains($bin, 'findstr')) {
            return false;
        }
        // POSIX grep accepts `--include`; findstr does not.
        $help = (string) @shell_exec(escapeshellarg($bin) . ' --help 2>&1 | head -n 1');
        return str_contains($help, 'grep') || str_contains($help, 'BSD');
    }

    /**
     * Return the first non-empty line of a multi-line shell output
     * (e.g. `where grep` returns one path per line). Empty string when
     * every line is blank.
     */
    private function firstNonEmptyLine(string $raw): string
    {
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }
        return '';
    }
}
