<?php

use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\User;
use Falak\Secrets\Contracts\AccessorType;
use Falak\Secrets\Domain\Models\AccessLogEntry;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = memberOf();
    $this->projectId = projects_default_env($this->organization)->project_id;
});

/**
 * @param  list<string>  $abilities
 */
function secrets_token(User $user, string $organizationId, array $abilities): string
{
    $token = $user->createToken('cli', $abilities);
    $token->accessToken->forceFill(['organization_id' => $organizationId])->save();
    auth()->forgetGuards();

    return $token->plainTextToken;
}

it('lists, creates, sets values, rolls back and deletes secrets with a token', function () {
    $token = secrets_token($this->user, $this->organization->id, ['secrets.view', 'secrets.manage']);

    $created = $this->withToken($token)->postJson('/api/v1/secrets', ['name' => 'API_KEY', 'scope' => 'project', 'scope_id' => strtoupper($this->projectId), 'value' => 'v1-SECRET'])
        ->assertCreated()->assertJsonPath('data.scope_id', $this->projectId);
    $id = $created->json('data.id');

    $this->withToken($token)->putJson("/api/v1/secrets/{$id}/value", ['value' => 'v2-SECRET'])->assertOk()->assertJsonPath('data.version', 2);
    $this->withToken($token)->postJson("/api/v1/secrets/{$id}/rollback", ['version' => 1])->assertOk()->assertJsonPath('data.current_version', 3);

    $list = $this->withToken($token)->getJson("/api/v1/secrets?scope=project&scope_id={$this->projectId}")->assertOk()->assertJsonPath('data.0.name', 'API_KEY');
    expect($list->getContent())->not->toContain('v1-SECRET')
        ->and($this->withToken($token)->getJson('/api/v1/secrets/'.strtoupper($id))->assertOk()->getContent())->not->toContain('v2-SECRET');

    $this->withToken($token)->deleteJson("/api/v1/secrets/{$id}")->assertNoContent();
    $this->withToken($token)->getJson("/api/v1/secrets/{$id}")->assertNotFound();
});

it('checks token abilities and the organization', function () {
    $secret = secrets_create($this->organization, 'X', 'y');
    $viewOnly = secrets_token($this->user, $this->organization->id, ['secrets.view']);

    $this->withToken($viewOnly)->getJson('/api/v1/secrets')->assertOk();
    $this->withToken($viewOnly)->putJson("/api/v1/secrets/{$secret->id}/value", ['value' => 'z'])->assertForbidden();
    $this->withToken($viewOnly)->postJson('/api/v1/secrets', ['name' => 'Y', 'scope' => 'organization', 'scope_id' => $this->organization->id, 'value' => 'z'])->assertForbidden();

    [$stranger, $other] = memberOf();
    $foreign = secrets_create($other, 'FOREIGN', 'x');
    $this->withToken($viewOnly)->getJson("/api/v1/secrets/{$foreign->id}")->assertNotFound();
});

it('reveals over the API only with an explicit secrets.reveal ability', function () {
    $secret = secrets_create($this->organization, 'PUBLIC_ID', 'pk_live_1', attributes: ['sensitive' => false]);
    $sensitive = secrets_create($this->organization, 'DB_PASS', 'hunter2');

    $wildcard = secrets_token($this->user, $this->organization->id, ['*']);
    $this->withToken($wildcard)->postJson("/api/v1/secrets/{$secret->id}/reveal")->assertForbidden();

    $reveal = secrets_token($this->user, $this->organization->id, ['secrets.view', 'secrets.reveal']);
    $this->withToken($reveal)->postJson("/api/v1/secrets/{$secret->id}/reveal")->assertOk()->assertJsonPath('data.value', 'pk_live_1');
    $this->withToken($reveal)->postJson("/api/v1/secrets/{$sensitive->id}/reveal")->assertForbidden();

    $log = AccessLogEntry::query()->sole();
    expect($log->actor_type)->toBe(AccessorType::ApiToken)->and($log->user_id)->toBe($this->user->id)->and($log->reason)->toBe('API token "cli"');

    // The ability is not enough without the permission (a viewer).
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->withToken(secrets_token($viewer, $this->organization->id, ['secrets.view', 'secrets.reveal']))->postJson("/api/v1/secrets/{$secret->id}/reveal")->assertForbidden();
});
