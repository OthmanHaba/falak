<?php

use Kiln\Deployments\Contracts\DeploymentTrigger;
use Kiln\Sites\Contracts\ComposeInspector;
use Kiln\Sites\Contracts\SiteFactory;
use Kiln\Templates\Application\Catalog\Catalog;
use Kiln\Templates\Tests\Support\FakeComposeInspector;
use Kiln\Templates\Tests\Support\FakeDeploymentTrigger;
use Kiln\Templates\Tests\Support\FakeSiteFactory;

require_once __DIR__.'/../../../Sites/tests/Support/helpers.php';

const TEMPLATES_FIXTURE_TEMPLATE = <<<'YAML'
name: Hello Stack
slug: hello
version: 1.2.0
description: A web app with a worker, for tests.
category: dev-tools
icon: docker
docs: https://example.com/docs
stateful: true
min_memory_mb: 256
popular: true
tags: [demo]
public:
  - service: web
    port: 8080
  - service: admin
    port: 9000
inputs:
  - key: APP_SECRET
    type: secret
    generate: secret(32)
    label: App secret
  - key: DB_PASSWORD
    type: secret
    generate: password(20)
  - key: ADMIN_EMAIL
    type: email
    label: Admin email
  - key: TIMEZONE
    type: select
    options: [UTC, Europe/Berlin]
    default: UTC
  - key: SIGNUPS
    type: boolean
    default: false
  - key: WORKERS
    type: number
    default: 2
  - key: PUBLIC_URL
    type: string
    default: ${{ kiln.url(web) }}
  - key: DATABASE_URL
    type: string
    default: ${{ postgres.DATABASE_URL }}
YAML;

const TEMPLATES_FIXTURE_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:1.29.1-alpine
    expose: ["8080"]
    environment:
      APP_SECRET: ${APP_SECRET}
      APP_URL: ${{ kiln.url(web) }}
      ADMIN_HOST: ${{ kiln.domain(admin) }}
      SITE: ${{ kiln.site }}
      TZ: ${TIMEZONE:-UTC}
      DEPLOYMENT: ${KILN_DEPLOYMENT_ID}
      DOLLAR: "$$NOT_A_VARIABLE"
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://127.0.0.1:8080/"]
    volumes:
      - data:/data
  admin:
    image: ghcr.io/example/admin:2.0.1
    ports: ["9000"]
    environment:
      ADMIN_EMAIL: ${ADMIN_EMAIL}
      DB_PASSWORD: ${DB_PASSWORD}
      SIGNUPS: ${SIGNUPS}
      WORKERS: ${WORKERS}
      PUBLIC_URL: ${PUBLIC_URL}
      DATABASE_URL: ${DATABASE_URL}
volumes:
  data:
YAML;

/**
 * Point the catalog at a temporary directory holding the given templates (slug => [template.yaml, compose.yaml]).
 *
 * @param  array<string, array{0: string, 1: string, 2?: string}>  $templates
 */
function templates_fixture_catalog(array $templates = ['hello' => [TEMPLATES_FIXTURE_TEMPLATE, TEMPLATES_FIXTURE_COMPOSE]]): string
{
    $path = sys_get_temp_dir().'/kiln-templates-'.bin2hex(random_bytes(6));

    foreach ($templates as $slug => $files) {
        mkdir("{$path}/{$slug}", 0777, true);
        file_put_contents("{$path}/{$slug}/template.yaml", $files[0]);
        file_put_contents("{$path}/{$slug}/compose.yaml", $files[1]);

        if (isset($files[2])) {
            file_put_contents("{$path}/{$slug}/icon.svg", $files[2]);
        }
    }

    config(['templates.catalog_path' => $path]);
    app()->forgetInstance(Catalog::class);

    return $path;
}

/**
 * @return array{sites: FakeSiteFactory, deployments: FakeDeploymentTrigger, inspector: FakeComposeInspector}
 */
function templates_fakes(): array
{
    $fakes = ['sites' => new FakeSiteFactory, 'deployments' => new FakeDeploymentTrigger, 'inspector' => new FakeComposeInspector];

    app()->instance(SiteFactory::class, $fakes['sites']);
    app()->instance(DeploymentTrigger::class, $fakes['deployments']);
    app()->instance(ComposeInspector::class, $fakes['inspector']);

    return $fakes;
}
