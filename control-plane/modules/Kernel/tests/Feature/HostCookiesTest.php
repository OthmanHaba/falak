<?php

use Falak\Identity\Contracts\Role;
use Falak\Kernel\Http\HostCookies;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * @return array<string, Cookie>
 */
function kernel_cookies(TestResponse $response): array
{
    $cookies = [];

    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie;
    }

    return $cookies;
}

it('sets __Host- prefixed session and CSRF cookies: host-only, Secure, Path=/', function () {
    $cookies = kernel_cookies($this->get('/login')->assertOk());

    expect(array_keys($cookies))->toContain(HostCookies::SESSION, HostCookies::XSRF)
        ->not->toContain('XSRF-TOKEN');

    foreach ([HostCookies::SESSION, HostCookies::XSRF] as $name) {
        $cookie = $cookies[$name];
        expect(str_starts_with($name, '__Host-'))->toBeTrue()
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->getDomain())->toBeNull()
            ->and($cookie->getPath())->toBe('/')
            ->and((string) $cookie)->not->toContain('domain=');
    }

    // The session stays HttpOnly; axios / fetch read the CSRF cookie.
    expect($cookies[HostCookies::SESSION]->isHttpOnly())->toBeTrue()
        ->and($cookies[HostCookies::XSRF]->isHttpOnly())->toBeFalse();
});

it('replaces the web group\'s CSRF middleware', function () {
    $web = app(Kernel::class)->getMiddlewareGroups()['web'];

    expect($web)->toContain(HostCookies::class)->not->toContain(ValidateCsrfToken::class);
});

it('accepts the prefixed CSRF cookie echoed in X-XSRF-TOKEN, and nothing else', function () {
    // The framework skips CSRF checks in tests: call the check itself.
    $middleware = new class(app(), app('encrypter')) extends HostCookies
    {
        public function matches(Request $request): bool
        {
            return $this->tokensMatch($request);
        }
    };
    $request = Request::create('/logout', 'POST');
    $request->setLaravelSession($session = app('session')->driver());
    $session->start();
    $echo = fn (string $token) => Crypt::encrypt(CookieValuePrefix::create(HostCookies::XSRF, Crypt::getKey()).$token, HostCookies::serialized());

    $request->headers->set('X-XSRF-TOKEN', $echo($session->token()));
    expect($middleware->matches($request))->toBeTrue();

    $request->headers->set('X-XSRF-TOKEN', $echo('planted-by-a-preview'));
    expect($middleware->matches($request))->toBeFalse();

    $request->headers->set('X-XSRF-TOKEN', 'not-encrypted');
    expect($middleware->matches($request))->toBeFalse();
});

it('logs in through the prefixed cookies', function () {
    [$user] = memberOf(null, Role::Owner);
    $user->forceFill(['password' => 'correct horse battery'])->save();

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'correct horse battery']);

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user);
    expect(array_keys(kernel_cookies($response)))->toContain(HostCookies::SESSION);
});
