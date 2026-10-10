<?php

namespace Falak\Edge\Application;

use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\PreviewRecord;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareError;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deletes the DNS records of previews that are gone. A record left pointing at an address that no longer serves the
 * name invites a subdomain takeover, so a record whose deletion failed stays as a tombstone (`deleting`) and is
 * retried (every ten minutes, scheduled) until Cloudflare confirms; records of sites that no longer exist become
 * tombstones too.
 */
final class PreviewRecords
{
    public function __construct(private readonly SiteDirectory $sites) {}

    /** Delete a record now, or leave its tombstone for the retries. */
    public function delete(PreviewRecord $record): bool
    {
        $record->forceFill(['deleting' => true])->save();
        $credential = $record->dns_credential_id !== null ? DnsCredential::query()->find($record->dns_credential_id) : null;

        if ($record->record_id === null) {
            $record->delete();

            return true;
        }

        if ($credential === null) {
            // The credential is gone: nothing can delete it anymore; the tombstone says so in the UI and logs.
            $record->forceFill(['error' => 'The DNS credential that created this record was removed: delete it in Cloudflare.', 'attempts' => $record->attempts + 1])->save();
            Log::warning('edge: preview record without credential', ['host' => $record->host]);

            return false;
        }

        try {
            CloudflareApi::with($credential->api_token)->deleteRecord($record->zone_id, $record->record_id);
            $record->delete();

            return true;
        } catch (CloudflareError $e) {
            $record->forceFill(['attempts' => $record->attempts + 1, 'error' => Str::limit($e->getMessage(), 990)])->save();
            Log::warning('edge: preview record not deleted yet', ['host' => $record->host, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Tombstones are retried; records of sites that no longer exist become tombstones.
     *
     * @return int records deleted
     */
    public function reconcile(): int
    {
        foreach (PreviewRecord::query()->where('deleting', false)->whereNotNull('site_id')->get() as $record) {
            if ($this->sites->find((string) $record->site_id) === null) {
                $record->forceFill(['deleting' => true])->save();
            }
        }

        $deleted = 0;

        foreach (PreviewRecord::query()->where('deleting', true)->orderBy('updated_at')->limit(200)->get() as $record) {
            $deleted += (int) $this->delete($record);
        }

        return $deleted;
    }
}
