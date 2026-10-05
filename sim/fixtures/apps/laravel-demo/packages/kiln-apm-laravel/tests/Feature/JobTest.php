<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class KilnTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public function __construct(public string $mode = 'ok')
    {
    }

    public function handle(): void
    {
        DB::select('select 1');

        if ($this->mode === 'fail') {
            throw new RuntimeException('job exploded');
        }
    }
}

class KilnRetryJob extends KilnTestJob
{
    public $tries = 3;
}

beforeEach(function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'connection' => 'testing']);
    config()->set('queue.failed.driver', 'null');

    Schema::create('jobs', function ($table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Route::get('/dispatch/{mode}', function ($mode) {
        $mode === 'retry' ? KilnRetryJob::dispatch('fail') : KilnTestJob::dispatch($mode);

        return 'queued';
    });
});

it('propagates trace context in the job payload and links the job trace to the dispatcher', function () {
    $this->get('/dispatch/ok')->assertOk();
    $request = $this->transport->spansOfType('request')[0];

    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    expect($payload['kiln']['traceparent'])->toStartWith('00-'.$request['traceId'].'-');

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    $jobs = $this->transport->spansOfType('job');
    expect($jobs)->toHaveCount(1);

    $job = $jobs[0];
    expect($job['kind'])->toBe(5)
        ->and($job['traceId'])->not->toBe($request['traceId'])
        ->and($job)->not->toHaveKey('parentSpanId')
        ->and($job['links'][0]['traceId'])->toBe($request['traceId'])
        ->and($job['attrs'])->toMatchArray([
            'kiln.event.type' => 'job',
            'messaging.destination.name' => 'default',
            'kiln.job.class' => KilnTestJob::class,
            'kiln.job.attempt' => 1,
            'kiln.job.status' => 'processed',
        ]);

    // The job's query belongs to the job trace; queue:work itself is not traced.
    $jobQueries = array_filter($this->transport->spansOfType('query'), fn ($q) => $q['traceId'] === $job['traceId']);
    expect($jobQueries)->not->toBeEmpty()
        ->and(array_filter($this->transport->spansOfType('command'), fn ($c) => $c['attrs']['kiln.command.name'] === 'queue:work'))->toBeEmpty();
});

it('records failed jobs with an unhandled exception', function () {
    $this->get('/dispatch/fail');
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    $job = $this->transport->spansOfType('job')[0];
    expect($job['attrs']['kiln.job.status'])->toBe('failed')
        ->and($job['status']['code'])->toBe(2);

    $event = collect($job['events'])->firstWhere('name', 'exception');
    expect($event['attrs']['exception.message'])->toBe('job exploded')
        ->and($event['attrs']['kiln.exception.handled'])->toBeFalse();
});

it('records released jobs', function () {
    $this->get('/dispatch/retry');
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    $job = $this->transport->spansOfType('job')[0];
    expect($job['attrs']['kiln.job.status'])->toBe('released')
        ->and($job['attrs']['kiln.job.class'])->toBe(KilnRetryJob::class);
});

it('records sync jobs as child spans of the current trace', function () {
    config()->set('queue.default', 'sync');
    Route::get('/sync', function () {
        KilnTestJob::dispatch();

        return 'ok';
    });

    $this->get('/sync')->assertOk();

    $request = $this->transport->spansOfType('request')[0];
    $job = $this->transport->spansOfType('job')[0];

    expect($job['traceId'])->toBe($request['traceId'])
        ->and($job['parentSpanId'])->toBe($request['spanId'])
        ->and($job['attrs']['kiln.job.status'])->toBe('processed');
});
