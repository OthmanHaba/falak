<?php

use Falak\Identity\Contracts\Role;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Templates\Application\Catalog\TemplateParser;
use Falak\Templates\Application\Compose\SiteCompose;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';

const SITE_COMPOSE = <<<'YAML'
services:
  app:
    image: ghcr.io/acme/app:4.1.0
    expose: ["3000"]
    environment:
      APP_URL: https://shop.acme.com
      HOST: shop.acme.com
      SECRET_KEY_BASE: ${SECRET_KEY_BASE}
      DB_PASSWORD: ${DB_PASSWORD}
      SESSION_ID: ${SESSION_ID}
      DATABASE_URL: ${DATABASE_URL}
      MODE: ${MODE}
      FEATURE_X: ${FEATURE_X}
      MISSING: ${NOT_IN_VARIABLES}
    volumes: [data:/data]
volumes:
  data:
YAML;

beforeEach(function () {
    templates_fixture_catalog();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    config(['sites.test_domain' => 'falak.test']);

    $this->site = projects_site($this->organization, 'Shop', [
        'SECRET_KEY_BASE' => 'q8Zr2LwX0pVb7Ns4Kd9Tf1Hy6Jm3Ge5A',
        'DB_PASSWORD' => 'correct horse',
        'SESSION_ID' => '2f1c3a4b-5d6e-4f70-8a9b-0c1d2e3f4a5b',
        'DATABASE_URL' => '${{ postgres.DATABASE_URL }}',
        'MODE' => 'production',
        'FEATURE_X' => 'true',
        'CALLBACK' => 'https://shop.acme.com/callback',
    ], attributes: ['runtime' => 'compose', 'framework' => 'docker']);

    app()->instance(SiteCompose::class, new class implements SiteCompose
    {
        public function content(SiteData $site): ?string
        {
            return SITE_COMPOSE;
        }

        public function publicServices(SiteData $site): array
        {
            return [['service' => 'app', 'port' => 3000, 'domain' => 'shop.acme.com']];
        }
    });
});

it('drafts a template from a compose site without leaking secrets', function () {
    $draft = $this->postJson("/settings/templates/from-site/{$this->site->id}")->assertOk()->json('data');

    expect($draft['problems'])->toBe([])
        ->and($draft['compose_yaml'])->toContain('APP_URL: ${{ falak.url(app) }}')->toContain('HOST: ${{ falak.domain(app) }}')
        ->and($draft['template_yaml'])
        ->not->toContain('q8Zr2LwX0pVb7Ns4Kd9Tf1Hy6Jm3Ge5A')
        ->not->toContain('correct horse')
        ->not->toContain('2f1c3a4b-5d6e-4f70-8a9b-0c1d2e3f4a5b');

    $template = (new TemplateParser)->parse($draft['template_yaml'], $draft['compose_yaml']);

    expect($template->slug)->toBe('shop')
        ->and($template->stateful)->toBeTrue()
        ->and($template->public)->toBe([['service' => 'app', 'port' => 3000]])
        ->and((string) $template->input('SECRET_KEY_BASE')->generate)->toBe('secret(32)')
        ->and((string) $template->input('DB_PASSWORD')->generate)->toBe('password(16)')
        ->and((string) $template->input('SESSION_ID')->generate)->toBe('uuid')
        ->and($template->input('DATABASE_URL')->default)->toBe('${{ postgres.DATABASE_URL }}')
        ->and($template->input('MODE')->default)->toBe('production')
        ->and($template->input('FEATURE_X')->type->value)->toBe('boolean')
        ->and($template->input('CALLBACK')->default)->toBe('${{ falak.url(app) }}/callback')
        ->and($template->input('NOT_IN_VARIABLES')->required)->toBeTrue();

    // The draft saves as-is.
    $this->postJson('/settings/templates', ['template_yaml' => $draft['template_yaml'], 'compose_yaml' => $draft['compose_yaml']])->assertCreated();
});

it('refuses sites without stored compose content and other organizations\' sites', function () {
    app()->instance(SiteCompose::class, new class implements SiteCompose
    {
        public function content(SiteData $site): ?string
        {
            return null;
        }

        public function publicServices(SiteData $site): array
        {
            return [];
        }
    });

    $this->postJson("/settings/templates/from-site/{$this->site->id}")->assertUnprocessable()->assertJsonValidationErrors('site');

    actingAsMember(Role::Owner);
    $this->postJson("/settings/templates/from-site/{$this->site->id}")->assertNotFound();
});
