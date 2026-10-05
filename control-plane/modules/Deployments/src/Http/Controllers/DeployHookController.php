<?php

namespace Falak\Deployments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Contracts\SiteDirectory;

/**
 * GET|POST /api/deploy/{token} — deploy hook URL (CI, chat ops). Reserved query parameters:
 * falak_deploy_branch, falak_deploy_commit, falak_deploy_author, falak_deploy_message. Every other
 * parameter becomes FALAK_VAR_<NAME> in the deploy script environment.
 */
final class DeployHookController extends Controller
{
    private const RESERVED = ['falak_deploy_branch', 'falak_deploy_commit', 'falak_deploy_author', 'falak_deploy_message'];

    public function __invoke(Request $request, string $token, SiteDirectory $sites, TriggerDeployment $trigger): JsonResponse
    {
        $settings = strlen($token) >= 32 ? SiteSettings::query()->where('hook_token_hash', hash('sha256', $token))->first() : null;
        $site = $settings ? $sites->find($settings->site_id) : null;

        if ($settings === null || $site === null || ! hash_equals((string) $settings->hook_token, $token)) {
            return response()->json(['message' => 'Unknown deploy hook.'], 404);
        }

        $params = $request->query();
        $variables = [];

        foreach ($params as $key => $value) {
            if (in_array($key, self::RESERVED, true) || ! is_scalar($value)) {
                continue;
            }

            $name = 'FALAK_VAR_'.strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '_', (string) $key));

            if (count($variables) >= 50 || strlen((string) $value) > 4096) {
                throw ValidationException::withMessages([$key => 'Too many or too large custom variables.']);
            }

            $variables[$name] = (string) $value;
        }

        $deployment = $trigger(
            $site,
            Trigger::Hook,
            branch: self::param($params, 'falak_deploy_branch'),
            commit: self::param($params, 'falak_deploy_commit'),
            message: self::param($params, 'falak_deploy_message'),
            author: self::param($params, 'falak_deploy_author'),
            variables: $variables,
        );

        return response()->json(['data' => [
            'id' => $deployment->id,
            'status' => $deployment->status->value,
            'number' => $deployment->number,
            'url' => $deployment->url(),
        ]], 202);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function param(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, 1000) : null;
    }
}
