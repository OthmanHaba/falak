<?php

/*
 * The panel runs as a FrankenPHP worker (Octane): one application instance per PHP thread serves request after
 * request. These tests boot a real Octane worker on the app (file SQLite database, cookie sessions) and send
 * consecutive requests through it, asserting nothing from one request leaks into the next.
 */

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\HtmlString;
use Falak\Identity\Application\Actions\CreateOrganization;
use Falak\Identity\Application\Actions\RegisterUser;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

const WORKER_ENV = ['DB_CONNECTION' => 'sqlite', 'SESSION_DRIVER' => 'cookie', 'CACHE_STORE' => 'array', 'APP_RUNNING_IN_CONSOLE' => false];

beforeEach(function () {
    $this->database = tempnam(sys_get_temp_dir(), 'falak-worker-').'.sqlite';
    touch($this->database);
    $this->savedEnv = [];

    foreach ([...WORKER_ENV, 'DB_DATABASE' => $this->database] as $key => $value) {
        $this->savedEnv[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : $value));
    }

    $this->client = new FakeClient([]);
    $this->worker = new FakeWorker(new ApplicationFactory(dirname(__DIR__, 2)), $this->client);
    $this->worker->boot();

    /** @var Application $app */
    $app = $this->worker->application();
    $app->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);

    // Like TestCase::withoutVite(): no built assets in unit tests.
    $app->instance(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null)
        {
            return new HtmlString('');
        }

        public function __call($method, $parameters)
        {
            return '';
        }

        public function __toString()
        {
            return '';
        }
    });

    $register = $app->make(RegisterUser::class);
    $this->alice = $register('Alice', 'alice@example.test', 'alice-password-123');
    $this->bob = $register('Bob', 'bob@example.test', 'bob-password-1234');
    $this->alice->markEmailAsVerified();
    $this->bob->markEmailAsVerified();
    $this->shared = $app->make(CreateOrganization::class)($this->alice, 'Shared Org');
    $app->forgetScopedInstances();

    $this->jars = [];
});

afterEach(function () {
    $this->worker?->terminate();
    HandleExceptions::flushState($this);
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);

    foreach ($this->savedEnv as $key => [$env, $server, $put]) {
        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
        putenv($put === false ? $key : "{$key}={$put}");
    }

    @unlink($this->database);
});

/**
 * One request through the worker as $who (separate cookie jars, so sessions never mix).
 */
function through(object $test, string $who, string $method, string $uri, array $data = []): Response
{
    if ($method !== 'GET' && ! isset($test->jars[$who]['XSRF-TOKEN'])) {
        through($test, $who, 'GET', '/login');
    }

    $jar = $test->jars[$who] ?? [];
    $server = ['HTTP_ACCEPT' => 'text/html', 'REMOTE_ADDR' => '127.0.0.1'];
    if (isset($jar['XSRF-TOKEN'])) {
        $server['HTTP_X_XSRF_TOKEN'] = $jar['XSRF-TOKEN'];
    }
    $request = Request::create($uri, $method, $data, $jar, [], $server);

    $test->worker->handle($request, new RequestContext(['request' => $request]));
    expect($test->client->errors)->toBe([]);

    /** @var Response $response */
    $response = end($test->client->responses);

    foreach ($response->headers->getCookies() as $cookie) {
        /** @var Cookie $cookie */
        $jar[$cookie->getName()] = $cookie->getValue();
    }
    $test->jars[$who] = $jar;

    return $response;
}

/**
 * @return array<string, mixed> the Inertia page object of a full HTML response
 */
function page(Response $response): array
{
    expect($response->getStatusCode())->toBe(200);
    preg_match('/data-page="([^"]+)"/', (string) $response->getContent(), $m);

    return json_decode(html_entity_decode($m[1] ?? '{}', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps the signed-in user, organization and team permissions per request', function () {
    through($this, 'alice', 'POST', '/login', ['email' => 'alice@example.test', 'password' => 'alice-password-123']);
    through($this, 'alice', 'PUT', '/organizations/current', ['organization_id' => $this->shared->id]);
    through($this, 'bob', 'POST', '/login', ['email' => 'bob@example.test', 'password' => 'bob-password-1234']);

    // The session selection wins even when the stored default says otherwise.
    // (Raw PDO: between requests the worker's base application is not meant to be used directly.)
    $pdo = new PDO('sqlite:'.$this->database);
    $personal = $pdo->query("select id from identity_organizations where personal = 1 and name = 'Alice''s Organization'")->fetchColumn();
    $pdo->prepare('update identity_users set current_organization_id = ? where id = ?')->execute([$personal, $this->alice->id]);

    foreach (range(1, 2) as $round) {
        $alice = page(through($this, 'alice', 'GET', '/settings/profile'))['props'];
        expect($alice['auth']['user']['email'])->toBe('alice@example.test')
            ->and($alice['organization']['current']['id'])->toBe($this->shared->id)
            ->and($alice['organization']['current']['role'])->toBe('owner')
            ->and(collect($alice['organization']['all'])->pluck('name')->all())->toContain('Shared Org');
        expect($this->worker->application()->make(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();

        $bob = page(through($this, 'bob', 'GET', '/settings/profile'))['props'];
        expect($bob['auth']['user']['email'])->toBe('bob@example.test')
            ->and($bob['organization']['current']['name'])->toBe("Bob's Organization")
            ->and(collect($bob['organization']['all'])->pluck('name')->all())->toBe(["Bob's Organization"]);

        $guest = through($this, 'guest', 'GET', '/settings/profile');
        expect($guest->getStatusCode())->toBe(302)->and($guest->headers->get('Location'))->toEndWith('/login');
    }

    expect((int) $pdo->query('select count(*) from identity_organizations')->fetchColumn())->toBe(3);
});

it('keeps module shared props and the Ziggy route() function on every full page load', function () {
    through($this, 'alice', 'POST', '/login', ['email' => 'alice@example.test', 'password' => 'alice-password-123']);

    foreach (range(1, 3) as $round) {
        $response = through($this, 'alice', 'GET', '/settings/profile');
        $props = page($response)['props'];

        expect($props)->toHaveKeys(['organization', 'falak', 'auth', 'flash'])
            ->and((string) $response->getContent())->toContain('const Ziggy=');
    }
});

it('flushes one-shot flashes and never shows them to the next request', function () {
    through($this, 'alice', 'POST', '/login', ['email' => 'alice@example.test', 'password' => 'alice-password-123']);
    through($this, 'bob', 'POST', '/login', ['email' => 'bob@example.test', 'password' => 'bob-password-1234']);

    through($this, 'alice', 'PUT', '/organizations/current', ['organization_id' => $this->shared->id]);

    expect(page(through($this, 'bob', 'GET', '/settings/profile'))['props']['flash'])->toBe([]);
});
