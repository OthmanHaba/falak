<?php

namespace Kiln\Identity\Application\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Kiln\Identity\Application\Actions\CreateApiToken;
use Kiln\Identity\Application\Actions\CreateOrganization;
use Kiln\Identity\Application\Actions\RegisterUser;
use Kiln\Identity\Domain\Models\User;

/**
 * First-run bootstrap for a self-hosted install: creates (or reuses) a user, the organization they own,
 * and optionally an API token for the CLI/automation. Safe to re-run.
 */
final class CreateAdminCommand extends Command
{
    protected $signature = 'kiln:admin
        {email : Admin e-mail address}
        {--name= : Display name (default: part of the e-mail before @)}
        {--password= : Password (generated and printed when omitted for a new user)}
        {--organization= : Organization to own (default: the personal organization)}
        {--token= : Also issue an API token with this name (full access)}
        {--json : Print the result as JSON (for scripts)}';

    protected $description = 'Create the first administrator, their organization and an optional API token';

    public function handle(RegisterUser $register, CreateOrganization $createOrganization, CreateApiToken $createToken): int
    {
        $email = mb_strtolower((string) $this->argument('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error("'{$email}' is not a valid e-mail address.");

            return self::INVALID;
        }

        $password = null;
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $password = $this->option('password') ?: Str::password(24, symbols: false);
            $user = $register($this->option('name') ?: Str::before($email, '@'), $email, $password);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $organization = ($name = $this->option('organization'))
            ? ($user->organizations()->where('name', $name)->first() ?? $createOrganization($user, $name))
            : $user->organizations()->where('personal', true)->firstOrFail();

        $token = ($tokenName = $this->option('token'))
            ? $createToken($user, $organization->id, $tokenName, ['*'])->plainTextToken
            : null;

        $result = array_filter([
            'user_id' => $user->id,
            'email' => $user->email,
            'password' => $password,
            'organization_id' => $organization->id,
            'organization' => $organization->name,
            'token' => $token,
        ], fn ($value) => $value !== null);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info($password === null ? "Using existing user {$email}." : "Created admin {$email}.");
        $this->components->twoColumnDetail('Organization', "{$organization->name} ({$organization->id})");

        if ($password !== null && ! $this->option('password')) {
            $this->components->twoColumnDetail('Password', $password);
        }

        if ($token !== null) {
            $this->components->twoColumnDetail('API token', $token);
        }

        return self::SUCCESS;
    }
}
