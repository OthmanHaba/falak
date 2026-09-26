<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Edge\Domain\Enums\InstallStatus;
use Kiln\Edge\Domain\Enums\LbPolicy;
use Kiln\Edge\Domain\Enums\WwwRedirect;
use Kiln\Edge\Domain\Models\Certificate;
use Kiln\Edge\Domain\Models\CertificateInstall;
use Kiln\Edge\Domain\Models\DnsCredential;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Domain\Models\Header;
use Kiln\Edge\Domain\Models\LoadBalancer;
use Kiln\Edge\Domain\Models\Redirect;
use Kiln\Edge\Domain\Models\SecurityRule;
use Kiln\Edge\Domain\Models\SiteSetting;
use Kiln\Edge\Events\DomainAdded;
use Kiln\Edge\Events\DomainRemoved;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Contracts\ServerType;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers, 'agents' => $this->agents] = edge_fakes();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->org = $this->organization->id;
    $this->web = edge_server($this->servers, $this->org);
    $this->site = edge_site($this->sites, $this->org, [$this->web->id], ['testDomain' => 'shop.kiln.test']);
    $this->base = "/sites/{$this->site->id}";
});

it('renders the domains page', function () {
    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'name' => 'shop.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::ToWww, 'tls_mode' => TlsMode::Auto]);
    edge_server($this->servers, $this->org, ['type' => ServerType::LoadBalancer, 'name' => 'lb-1']);

    $this->get("{$this->base}/domains")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Edge/Domains', false)
        ->where('site.id', $this->site->id)
        ->where('domains.0.hosts', ['www.shop.com', 'shop.com'])
        ->where('testDomain', 'shop.kiln.test')
        ->has('lbServers', 1)
        ->has('edgeServers', 1)
        ->where('can.manage', true)
        ->where('can.manage_dns', false));

    $this->get("{$this->base}/routing")->assertOk()->assertInertia(fn ($page) => $page->component('Edge/Routing', false)->where('can.manage', true));
});

it('adds domains, making the first one primary, and applies the edge', function () {
    Event::fake([DomainAdded::class, DomainRemoved::class]);

    $this->post("{$this->base}/domains", ['name' => 'Shop.COM.', 'www_redirect' => 'to_www'])->assertSessionHasNoErrors();
    $this->post("{$this->base}/domains", ['name' => 'shop.de'])->assertSessionHasNoErrors();

    $domains = Domain::query()->orderBy('name')->get();
    expect($domains->pluck('name')->all())->toBe(['shop.com', 'shop.de'])
        ->and($domains->firstWhere('name', 'shop.com')->is_primary)->toBeTrue()
        ->and($domains->firstWhere('name', 'shop.de')->is_primary)->toBeFalse();
    Event::assertDispatched(DomainAdded::class, 2);

    $last = collect($this->agents->ofType('edge.caddy.apply', $this->web->id))->last();
    expect($last['payload']['sites'][0]['domains'])->toBe(['www.shop.com', 'shop.de', 'shop.kiln.test']);

    $this->put("{$this->base}/domains/{$domains[1]->id}/primary")->assertSessionHasNoErrors();
    expect($domains[1]->refresh()->is_primary)->toBeTrue()->and($domains[0]->refresh()->is_primary)->toBeFalse();

    $this->delete("{$this->base}/domains/{$domains[1]->id}")->assertSessionHasNoErrors();
    expect($domains[0]->refresh()->is_primary)->toBeTrue();
    Event::assertDispatched(DomainRemoved::class);
});

it('validates domains', function () {
    $other = edge_site($this->sites, $this->org, [$this->web->id]);
    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $other->id, 'name' => 'taken.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::ToWww, 'tls_mode' => TlsMode::Auto]);

    $this->post("{$this->base}/domains", ['name' => 'not a domain'])->assertSessionHasErrors('name');
    $this->post("{$this->base}/domains", ['name' => 'taken.com'])->assertSessionHasErrors('name');
    $this->post("{$this->base}/domains", ['name' => 'www.taken.com'])->assertSessionHasErrors('name');
    $this->post("{$this->base}/domains", ['name' => 'shop.kiln.test'])->assertSessionHasErrors('name');
    $this->post("{$this->base}/domains", ['name' => '*.wild.com'])->assertSessionHasErrors('tls_mode');
    $this->post("{$this->base}/domains", ['name' => '*.wild.com', 'tls_mode' => 'dns'])->assertSessionHasErrors('dns_credential_id');
    $this->post("{$this->base}/domains", ['name' => 'www.x.com', 'www_redirect' => 'to_apex'])->assertSessionHasErrors('www_redirect');
    $this->post("{$this->base}/domains", ['name' => 'x.com', 'tls_mode' => 'custom'])->assertSessionHasErrors('certificate_id');

    expect(Domain::query()->count())->toBe(1);
});

it('uploads certificates, installs them and uses them for domains', function () {
    $pem = edge_self_signed(['secure.shop.com', '*.shop.com']);

    $this->post("{$this->base}/certificates", ['certificate' => $pem['cert'], 'private_key' => $pem['key']])->assertSessionHasNoErrors();

    $certificate = Certificate::query()->sole();
    expect($certificate->domains)->toBe(['secure.shop.com', '*.shop.com'])
        ->and($certificate->getRawOriginal('key_pem'))->not->toContain('PRIVATE KEY')
        ->and($certificate->not_after->isFuture())->toBeTrue();

    [$install] = $this->agents->ofType('edge.cert.install', $this->web->id);
    expect($install['payload'])->toMatchArray(['name' => $certificate->name, 'state' => 'present'])
        ->and(CertificateInstall::query()->sole()->status)->toBe(InstallStatus::Pending);

    $this->post("{$this->base}/domains", ['name' => 'api.shop.com', 'tls_mode' => 'custom', 'certificate_id' => $certificate->id])->assertSessionHasNoErrors();
    $this->post("{$this->base}/domains", ['name' => 'other.com', 'tls_mode' => 'custom', 'certificate_id' => $certificate->id])->assertSessionHasErrors('certificate_id');

    // Not deletable while in use; deletion uninstalls.
    $this->delete("{$this->base}/certificates/{$certificate->id}")->assertSessionHasErrors('certificate');
    Domain::query()->delete();
    $this->delete("{$this->base}/certificates/{$certificate->id}")->assertSessionHasNoErrors();

    expect(Certificate::query()->count())->toBe(0)
        ->and(collect($this->agents->ofType('edge.cert.install'))->last()['payload'])->toBe(['name' => $certificate->name, 'state' => 'absent']);
});

it('rejects invalid certificates', function () {
    $pem = edge_self_signed(['a.com']);
    $other = edge_self_signed(['a.com']);

    $this->post("{$this->base}/certificates", ['certificate' => 'nope', 'private_key' => $pem['key']])->assertSessionHasErrors('certificate');
    $this->post("{$this->base}/certificates", ['certificate' => $pem['cert'], 'private_key' => $other['key']])->assertSessionHasErrors('certificate');
    $this->post("{$this->base}/certificates", ['certificate' => $pem['cert'], 'private_key' => 'garbage'])->assertSessionHasErrors('certificate');

    expect(Certificate::query()->count())->toBe(0);
});

it('manages DNS credentials as an admin only and never exposes tokens', function () {
    $this->post('/edge/dns-credentials', ['provider' => 'cloudflare', 'name' => 'CF', 'api_token' => str_repeat('t', 40)])->assertForbidden();

    [$admin] = actingAsMember(Role::Admin, $this->organization);
    $this->post('/edge/dns-credentials', ['provider' => 'cloudflare', 'name' => 'CF', 'api_token' => str_repeat('t', 40)])->assertSessionHasNoErrors();

    $credential = DnsCredential::query()->sole();
    expect($credential->api_token)->toBe(str_repeat('t', 40))
        ->and($credential->getRawOriginal('api_token'))->not->toBe(str_repeat('t', 40));

    $this->get("{$this->base}/domains")->assertInertia(fn ($page) => $page->where('dnsCredentials.0.name', 'CF')->missing('dnsCredentials.0.api_token')->where('can.manage_dns', true));

    $this->post("{$this->base}/domains", ['name' => '*.shop.com', 'tls_mode' => 'dns', 'dns_credential_id' => $credential->id])->assertSessionHasNoErrors();
    $this->delete("/edge/dns-credentials/{$credential->id}")->assertSessionHasErrors('credential');
});

it('manages redirects, security rules, headers and settings', function () {
    $this->post("{$this->base}/redirects", ['from' => '/old', 'to' => '/new', 'status' => 302])->assertSessionHasNoErrors();
    $this->post("{$this->base}/redirects", ['from' => 'old', 'to' => '/new', 'status' => 302])->assertSessionHasErrors('from');
    $this->post("{$this->base}/redirects", ['from' => '/x', 'to' => 'javascript:alert(1)', 'status' => 301])->assertSessionHasErrors('to');
    $this->post("{$this->base}/redirects", ['from' => '/x', 'to' => '/y', 'status' => 200])->assertSessionHasErrors('status');

    $this->post("{$this->base}/security-rules", ['path' => '/admin/*', 'username' => 'ops', 'password' => 'correct horse'])->assertSessionHasNoErrors();
    $this->post("{$this->base}/security-rules", ['path' => '/*', 'username' => 'all', 'password' => 'correct horse'])->assertSessionHasNoErrors();
    $this->post("{$this->base}/security-rules", ['path' => '/admin/*', 'username' => 'ops', 'password' => 'another one'])->assertSessionHasErrors('username');
    $this->post("{$this->base}/security-rules", ['username' => 'a:b', 'password' => 'correct horse'])->assertSessionHasErrors('username');

    $this->post("{$this->base}/headers", ['name' => 'X-Frame-Options', 'value' => 'DENY'])->assertSessionHasNoErrors();
    $this->post("{$this->base}/headers", ['name' => 'Location', 'value' => 'x'])->assertSessionHasErrors('name');
    $this->post("{$this->base}/headers", ['name' => 'X-Bad', 'value' => "a\nb"])->assertSessionHasErrors('value');

    $this->put("{$this->base}/edge-settings", ['allow_ips' => [], 'deny_ips' => ['203.0.113.0/24', '2001:db8::/32'], 'max_body_bytes' => 1024, 'encode' => true])->assertSessionHasNoErrors();
    $this->put("{$this->base}/edge-settings", ['allow_ips' => ['nope'], 'deny_ips' => [], 'encode' => true])->assertSessionHasErrors('allow_ips.0');

    $rule = SecurityRule::query()->where('username', 'ops')->sole();
    expect(Hash::check('correct horse', $rule->password_hash))->toBeTrue()
        ->and($rule->path)->toBe('/admin/*')
        ->and(SecurityRule::query()->where('username', 'all')->value('path'))->toBeNull()
        ->and(SiteSetting::query()->find($this->site->id)->deny_ips)->toBe(['203.0.113.0/24', '2001:db8::/32']);

    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'name' => 'shop.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);
    $this->delete("{$this->base}/redirects/".Redirect::query()->value('id'))->assertSessionHasNoErrors();
    $this->delete("{$this->base}/headers/".Header::query()->value('id'))->assertSessionHasNoErrors();
    $this->delete("{$this->base}/security-rules/{$rule->id}")->assertSessionHasNoErrors();

    $entry = collect($this->agents->ofType('edge.caddy.apply'))->last()['payload']['sites'][0];
    expect($entry)->toHaveKey('basic_auth')->not->toHaveKey('redirects')->not->toHaveKey('headers')
        ->and($entry['max_body_bytes'])->toBe(1024);

    $this->get("{$this->base}/routing")->assertInertia(fn ($page) => $page->has('rules', 1)->missing('rules.0.password_hash'));
});

it('configures a load balancer', function () {
    $second = edge_server($this->servers, $this->org);
    $site = edge_site($this->sites, $this->org, [$this->web->id, $second->id]);
    $lb = edge_server($this->servers, $this->org, ['type' => ServerType::LoadBalancer]);
    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $site->id, 'name' => 'lb.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);

    $this->put("/sites/{$site->id}/load-balancer", ['server_id' => $this->web->id, 'policy' => 'round_robin'])->assertSessionHasErrors('server_id');
    $this->put("/sites/{$site->id}/load-balancer", ['server_id' => $lb->id, 'policy' => 'ip_hash', 'health_uri' => '/up', 'weights' => [$this->web->id => 3, 'unknown' => 5]])->assertSessionHasNoErrors();

    $balancer = LoadBalancer::query()->sole();
    expect($balancer->policy)->toBe(LbPolicy::IpHash)->and($balancer->weights)->toBe([$this->web->id => 3]);

    $front = collect($this->agents->ofType('edge.caddy.apply', $lb->id))->last()['payload']['sites'][0];
    expect($front['upstreams'])->toHaveCount(4)->and($front['lb_policy'])->toBe('ip_hash');

    $this->delete("/sites/{$site->id}/load-balancer")->assertSessionHasNoErrors();
    expect(LoadBalancer::query()->count())->toBe(0)
        ->and(collect($this->agents->ofType('edge.caddy.apply', $lb->id))->last()['payload']['sites'])->toBe([]);
});

it('re-applies a server on demand', function () {
    $this->post("{$this->base}/edge/apply", ['server_id' => $this->web->id])->assertSessionHasNoErrors();
    $this->post("{$this->base}/edge/apply", ['server_id' => $this->web->id])->assertSessionHasNoErrors();
    $this->post("{$this->base}/edge/apply", ['server_id' => 'other'])->assertSessionHasErrors('server_id');

    expect($this->agents->ofType('edge.caddy.apply'))->toHaveCount(2);
});

it('lets viewers look but not touch', function () {
    [$viewer] = actingAsMember(Role::Viewer, $this->organization);

    $this->get("{$this->base}/domains")->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false));
    $this->post("{$this->base}/domains", ['name' => 'shop.com'])->assertForbidden();
    $this->post("{$this->base}/redirects", ['from' => '/a', 'to' => '/b', 'status' => 301])->assertForbidden();
    $this->put("{$this->base}/edge-settings", ['allow_ips' => [], 'deny_ips' => [], 'encode' => true])->assertForbidden();

    expect(Domain::query()->count())->toBe(0);
});

it('hides sites of other organizations', function () {
    actingAsMember(Role::Owner);

    $this->get("{$this->base}/domains")->assertNotFound();
    $this->get("{$this->base}/routing")->assertNotFound();
    $this->post("{$this->base}/domains", ['name' => 'evil.com'])->assertNotFound();
    $this->get('/sites/01HUNKNOWN0000000000000000/domains')->assertNotFound();
});
