<?php

namespace Kiln\Templates\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Templates\Domain\Models\CustomTemplate;
use Kiln\Templates\Domain\Models\CustomTemplateRevision;

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
