<?php

namespace Falak\Templates\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Templates\Domain\Models\CustomTemplate;
use Falak\Templates\Domain\Models\CustomTemplateRevision;
use Illuminate\Support\Facades\DB;

/**
 * Sites created from the template keep running; only the template (and its history) goes away.
 */
final class DeleteCustomTemplate
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(CustomTemplate $template, ?string $userId): void
    {
        DB::transaction(function () use ($template) {
            CustomTemplateRevision::query()->where('template_id', $template->id)->delete();
            $template->delete();
        });

        $this->audit->record('templates.deleted', 'template', $template->id, ['slug' => $template->slug], $template->organization_id, $userId);
    }
}
