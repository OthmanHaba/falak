<?php

namespace Falak\Functions\Application\Actions;

use DateTimeZone;
use Illuminate\Validation\Rule;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionSchedule;
use Falak\Identity\Contracts\AuditLog;
use Falak\Processes\Contracts\CronExpressions;
use Falak\Processes\Contracts\ProcessControl;
use Falak\Sites\Contracts\Data\SiteData;

/**
 * Create or change a function's schedule; the leader server's cron set follows (Processes converges it).
 */
final class SaveSchedule
{
    public function __construct(
        private readonly ProcessControl $processes,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'expression' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
                if (! CronExpressions::valid((string) $value)) {
                    $fail(CronExpressions::HINT);
                }
            }],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'overlap' => ['nullable', Rule::in(['allow', 'skip'])],
            'timeout_s' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated with rules()
     */
    public function __invoke(SiteData $site, CloudFunction $function, ?FunctionSchedule $schedule, array $data): FunctionSchedule
    {
        $schedule ??= new FunctionSchedule(['function_id' => $function->id]);
        $schedule->fill([
            'name' => trim((string) $data['name']),
            'expression' => (string) preg_replace('/\s+/', ' ', trim((string) $data['expression'])),
            'timezone' => $data['timezone'] ?? $schedule->timezone ?? 'UTC',
            'overlap' => $data['overlap'] ?? $schedule->overlap ?? 'skip',
            'timeout_s' => (int) ($data['timeout_s'] ?? $schedule->timeout_s ?? 300),
            'enabled' => (bool) ($data['enabled'] ?? $schedule->enabled ?? true),
        ]);
        $created = ! $schedule->exists;
        $schedule->save();

        $this->audit->record($created ? 'function.schedule.created' : 'function.schedule.updated', 'site', $site->id, $schedule->only(['name', 'expression', 'timezone', 'enabled']), $site->organizationId);
        $this->processes->converge(...$site->serverIds());

        return $schedule;
    }

    public function delete(SiteData $site, FunctionSchedule $schedule): void
    {
        $schedule->delete();
        $this->audit->record('function.schedule.deleted', 'site', $site->id, ['name' => $schedule->name], $site->organizationId);
        $this->processes->converge(...$site->serverIds());
    }
}
