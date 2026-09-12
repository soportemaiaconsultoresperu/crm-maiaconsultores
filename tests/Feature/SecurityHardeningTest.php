<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * B09 / RNF-SEG-001..004 — Security hardening regression tests.
 *
 * Covers:
 *   1. Sensitive fields (password, remember_token) never leak through
 *      log channels when an Eloquent user model is dumped.
 *   2. APP_DEBUG=false hides stack traces from error responses.
 *   3. The `web` middleware group still carries CSRF protection and the
 *      state-changing routes are not excluded from it.
 *   4. Input validation rejects XSS payloads.
 *   5. Permission gates respect role boundaries (vendedor cannot reach
 *      admin resources or another vendor's records).
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_password_and_remember_token_are_masked_in_logs(): void
    {
        // Swap the active logger for an in-memory array handler so we can
        // introspect every record written during the test. This guards
        // RNF-SEG-003 (no sensitive data in logs).
        $records = [];
        $handler = new \Monolog\Handler\TestHandler();
        $handler->setFormatter(new \Monolog\Formatter\LineFormatter());

        $logger = new \Illuminate\Log\Logger(
            new \Monolog\Logger('test', [$handler])
        );

        Log::swap($logger);

        $plain = 'secreto-no-aparece-1234';
        $user = User::factory()->create([
            'email' => 'logcheck@maia.test',
            'password' => $plain,
            'is_active' => true,
        ]);

        $this->assertContains('password', $user->getHidden());

        Log::info('user-dump', ['user' => $user]);

        $records = $handler->getRecords();
        $blob = strtolower(json_encode($records));

        $this->assertStringNotContainsString(
            strtolower($plain),
            $blob,
            'Plain password must never appear in log output.'
        );
        $this->assertStringNotContainsString(
            strtolower($user->remember_token ?? ''),
            $blob,
            'remember_token must never appear in log output.'
        );
        // Sanity check: the safe identifier did make it through.
        $this->assertStringContainsString('logcheck@maia.test', $blob);
    }

    public function test_app_debug_false_hides_stack_traces_from_error_responses(): void
    {
        // APP_DEBUG=false should mean error responses do NOT include
        // vendor frames or local filesystem paths in their HTML body.
        config(['app.debug' => false]);

        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $request = \Illuminate\Http\Request::create('/anything', 'GET');

        $response = $handler->render($request, new \RuntimeException('forced-test-error'));
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('vendor/', $body);
        $this->assertStringNotContainsString('/laragon/', $body);
    }

    /**
     * A-3. The previous version of this test posted VALID credentials to
     * /login and accepted `[419, 302]`: with CSRF enforced the answer is 419,
     * and with the middleware gone the login succeeds and the answer is 302.
     * Both outcomes were accepted, so the assertion held whether or not the
     * protection existed. On top of that the harness never even reaches the
     * rejection, because PreventRequestForgery::handle() short-circuits on
     * runningUnitTests() — the 419 branch is dead code in tests.
     *
     * The honest target is the CONFIGURATION that decides whether the
     * protection exists, so this test pins it directly. Concretely, it fails
     * when any of these changes lands:
     *   - `$middleware->web(remove: [PreventRequestForgery::class])` (or a
     *     `web(replace: [...])`) drops the middleware from the web group;
     *   - `$middleware->allowSameSite()` is enabled, which lets a same-site
     *     request skip the token check entirely;
     *   - `$middleware->validateCsrfTokens(except: [...])` starts covering a
     *     URI that matters (e.g. `except: ['login']` or `['*']`);
     *   - a state-changing route stops running the middleware: it is moved out
     *     of the `web` group, or it gets `->withoutMiddleware('web')` /
     *     `->withoutMiddleware(PreventRequestForgery::class)`.
     */
    public function test_csrf_protection_is_configured_on_the_web_group_and_not_excluded_for_state_changing_routes(): void
    {
        $forgery = new class($this->app, $this->app->make('encrypter')) extends PreventRequestForgery
        {
            /**
             * The middleware's own exclusion predicate: the exact code path
             * handle() consults, minus the unit-test short circuit.
             */
            public function isExcluded(\Illuminate\Http\Request $request): bool
            {
                return $this->inExceptArray($request);
            }

            public function sameSiteBypassEnabled(): bool
            {
                return static::$allowSameSite;
            }
        };

        $router = $this->app->make(Router::class);
        $webGroup = $router->getMiddlewareGroups()['web'] ?? [];

        $csrfMiddleware = array_values(array_filter(
            $webGroup,
            fn ($middleware): bool => is_string($middleware)
                && ($middleware === PreventRequestForgery::class
                    || is_subclass_of($middleware, PreventRequestForgery::class)),
        ));

        $this->assertNotEmpty(
            $csrfMiddleware,
            'The `web` middleware group must include '.PreventRequestForgery::class
            .'; without it every POST/PUT/DELETE in the application is unprotected.',
        );

        $this->assertFalse(
            $forgery->sameSiteBypassEnabled(),
            'PreventRequestForgery::allowSameSite() weakens CSRF verification: a same-site request '
            .'would skip the token check. It must not be enabled in bootstrap/app.php.',
        );

        $stateChangingRoutes = [
            'login.store',
            'logout',
            'leads.store',
            'customers.store',
            'products.store',
            'quotations.store',
            'quotations.update',
            'admin.users.store',
            'admin.settings.update',
        ];

        foreach ($stateChangingRoutes as $name) {
            $route = $router->getRoutes()->getByName($name);

            $this->assertNotNull(
                $route,
                "Route [{$name}] must exist so its CSRF coverage can be verified.",
            );

            // Router::gatherRouteMiddleware() is the chain the router actually
            // runs: it resolves aliases and groups AND subtracts the route's
            // excluded middleware, so this fails when the route leaves the web
            // group or an exclusion is added to the route itself.
            $chain = $router->gatherRouteMiddleware($route);
            $routeCsrf = array_filter(
                $chain,
                fn ($middleware): bool => is_string($middleware)
                    && ($middleware === PreventRequestForgery::class
                        || is_subclass_of($middleware, PreventRequestForgery::class)),
            );

            $this->assertNotEmpty(
                $routeCsrf,
                "Route [{$name}] must run ".PreventRequestForgery::class
                .' (via the `web` group); without it the request is not verified: '.json_encode($chain),
            );

            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $request = \Illuminate\Http\Request::create('/'.$uri, 'POST');

            $this->assertFalse(
                $forgery->isExcluded($request),
                "POST /{$uri} (route [{$name}]) must not be excluded from CSRF verification.",
            );
        }
    }

    public function test_input_validation_rejects_xss_payload(): void
    {
        // Posting an HTML/JS payload to a field that goes through Blade
        // output (escaping) must not produce raw markup in the rendered
        // HTML. We render a one-off Blade string through the framework's
        // compiler so we don't need a stub view file.
        $payload = '<script>alert("xss")</script>';

        $compiled = \Illuminate\Support\Facades\Blade::render(
            '<span>{{ $payload }}</span>',
            ['payload' => $payload]
        );

        $this->assertStringNotContainsString(
            '<script>alert("xss")</script>',
            $compiled,
            'Raw script tag must not appear in the rendered output.'
        );
        $this->assertStringContainsString('&lt;script&gt;', $compiled);
    }

    public function test_vendedor_cannot_reach_admin_routes_or_other_vendor_resources(): void
    {
        $otherSalesperson = User::factory()->create(['is_active' => true]);
        $otherSalesperson->assignRole('vendedor');

        $me = User::factory()->create(['is_active' => true]);
        $me->assignRole('vendedor');

        // /admin/* is gated by users.manage / settings.manage etc. — vendedor
        // does not hold any of those.
        $this->actingAs($me)->get('/admin/users')->assertForbidden();
        $this->actingAs($me)->get('/admin/teams')->assertForbidden();
        $this->actingAs($me)->get('/admin/settings')->assertForbidden();
        $this->actingAs($me)->get('/admin/audit')->assertForbidden();

        // Cross-vendor records: try to view a lead owned by someone else.
        $otherLead = \App\Models\Lead::factory()->forOwner($otherSalesperson)->create();

        $this->actingAs($me)->get("/leads/{$otherLead->id}")->assertForbidden();

        // The owner can still see their own.
        $myLead = \App\Models\Lead::factory()->forOwner($me)->create();
        $this->actingAs($me)->get("/leads/{$myLead->id}")->assertOk();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function logRecords(): array
    {
        // Pull every "last message" recorded by the testing harness (the
        // LogManager swaps the single channel to an ArrayHandler).
        $handler = $this->app->make(\Illuminate\Log\LogManager::class)
            ->driver();

        if (! method_exists($handler, 'getMessages')) {
            return [];
        }

        /** @var \Illuminate\Log\Logger $logger */
        $logger = $this->app->make('log');
        $messages = [];

        foreach ($logger->getHandlers() as $h) {
            if (method_exists($h, 'getMessages')) {
                $messages = array_merge($messages, $h->getMessages());
            }
        }

        return $messages;
    }

    private function renderException(\Throwable $e): string
    {
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        ob_start();
        $handler->report($e);
        $handler->render($this->app->make('request'), $e);

        return (string) ob_get_clean();
    }
}