<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which release each server of a site runs (what `current` points at), for Processes: a site's programs are only
 * supervised on a server once it has a release there, and carry that release's ids + environment in their env.
 * Releases keep the (encrypted) environment their `.env` was written with.
 *
 * Backfill: every site's active release on the servers of the deployment that last made it live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments_releases', function (Blueprint $table) {
            $table->text('environment')->nullable()->after('compose');
        });

        Schema::create('deployments_server_releases', function (Blueprint $table) {
            $table->ulid('site_id');
            $table->ulid('server_id')->index();
            $table->ulid('release_id');
            $table->ulid('deployment_id')->nullable();
            $table->timestamps();

            $table->primary(['site_id', 'server_id']);
        });

        $active = DB::table('deployments_releases')->where('status', 'active')->get(['id', 'site_id']);

        foreach ($active as $release) {
            $deployment = DB::table('deployments_deployments')->where('site_id', $release->site_id)->where('release_id', $release->id)
                ->where('status', 'succeeded')->orderByDesc('number')->first(['id']);

            if ($deployment === null) {
                continue;
            }

            $servers = DB::table('deployments_targets')->where('deployment_id', $deployment->id)->where('status', '!=', 'failed')->pluck('server_id');

            foreach ($servers as $serverId) {
                DB::table('deployments_server_releases')->insertOrIgnore([
                    'site_id' => $release->site_id, 'server_id' => $serverId, 'release_id' => $release->id, 'deployment_id' => $deployment->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments_server_releases');

        Schema::table('deployments_releases', function (Blueprint $table) {
            $table->dropColumn('environment');
        });
    }
};
