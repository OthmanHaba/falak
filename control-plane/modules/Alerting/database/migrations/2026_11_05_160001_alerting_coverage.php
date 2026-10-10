<?php

use Falak\Alerting\Application\DefaultRulePack;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.10.0 step 8 (alerts coverage): suggested-fix actions on alerts, a default channel per organization, the default
 * rule pack (rules tagged with their pack area; alerting_rule_packs records what was applied so a deleted rule is never
 * re-created) and stateful conditions (AlertConditions: raised once, resolved when they clear).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerting_alerts', function (Blueprint $table) {
            $table->string('action', 100)->nullable()->after('url');
        });

        Schema::table('alerting_notifications', function (Blueprint $table) {
            $table->string('action', 100)->nullable()->after('url');
        });

        Schema::table('alerting_channels', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('enabled');
        });

        Schema::table('alerting_rules', function (Blueprint $table) {
            $table->string('pack_key', 64)->nullable()->after('organization_id');
        });

        Schema::create('alerting_rule_packs', function (Blueprint $table) {
            $table->id();
            $table->ulid('organization_id');
            $table->string('pack_key', 64);
            $table->ulid('rule_id')->nullable();
            $table->json('patterns');
            $table->timestamp('applied_at');
            $table->unique(['organization_id', 'pack_key']);
        });

        Schema::create('alerting_conditions', function (Blueprint $table) {
            $table->id();
            $table->ulid('organization_id');
            $table->string('key', 255);
            $table->timestamp('since');
            $table->timestamp('raised_at')->nullable();
            $table->string('type', 100)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('url', 2000)->nullable();
            $table->timestamp('seen_at')->index();
            $table->unique(['organization_id', 'key']);
        });

        // The oldest channel of each organization becomes its default.
        $first = DB::table('alerting_channels')->select('organization_id', DB::raw('min(created_at) as created_at'))->groupBy('organization_id')->get();

        foreach ($first as $row) {
            $id = DB::table('alerting_channels')->where('organization_id', $row->organization_id)->where('created_at', $row->created_at)->orderBy('id')->value('id');
            DB::table('alerting_channels')->where('id', $id)->update(['is_default' => true]);
        }

        app(DefaultRulePack::class)->applyAll();
    }

    public function down(): void
    {
        Schema::dropIfExists('alerting_conditions');
        Schema::dropIfExists('alerting_rule_packs');

        Schema::table('alerting_rules', fn (Blueprint $table) => $table->dropColumn('pack_key'));
        Schema::table('alerting_channels', fn (Blueprint $table) => $table->dropColumn('is_default'));
        Schema::table('alerting_notifications', fn (Blueprint $table) => $table->dropColumn('action'));
        Schema::table('alerting_alerts', fn (Blueprint $table) => $table->dropColumn('action'));
    }
};
