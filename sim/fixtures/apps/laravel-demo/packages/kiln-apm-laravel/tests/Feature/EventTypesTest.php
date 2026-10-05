<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

class KilnWelcomeMail extends Mailable
{
    public function build()
    {
        return $this->subject('Welcome')->html('<p>hi</p>');
    }
}

class KilnInvoicePaid extends Notification
{
    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)->line('Paid');
    }
}

it('records outgoing requests and propagates traceparent', function () {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 201)]);
    Route::get('/out', function () {
        Http::get('https://api.example.com/v1/items?api_key=abc&page=2');

        return 'ok';
    });

    $this->get('/out')->assertOk();

    $root = $this->transport->spansOfType('request')[0];
    $out = $this->transport->spansOfType('outgoing_request')[0];

    expect($out['kind'])->toBe(3)
        ->and($out['parentSpanId'])->toBe($root['spanId'])
        ->and($out['attrs'])->toMatchArray([
            'http.request.method' => 'GET',
            'http.response.status_code' => 201,
            'server.address' => 'api.example.com',
            'url.full' => 'https://api.example.com/v1/items?api_key=%5Bredacted%5D&page=2',
        ]);

    Http::assertSent(fn ($request) => $request->header('traceparent')[0] === '00-'.$root['traceId'].'-'.$out['spanId'].'-01');
});

it('records mail', function () {
    Route::get('/mail', function () {
        Mail::to(['a@example.com', 'b@example.com'])->cc('c@example.com')->send(new KilnWelcomeMail);

        return 'ok';
    });

    $this->get('/mail')->assertOk();

    $mail = $this->transport->spansOfType('mail')[0];
    expect($mail['kind'])->toBe(1)
        ->and($mail['attrs'])->toMatchArray([
            'kiln.mail.class' => KilnWelcomeMail::class,
            'kiln.mail.recipients_count' => 3,
            'kiln.mail.mailer' => 'array',
        ]);
});

it('records notifications sent and failed', function () {
    Route::get('/notify', function () {
        $notifiable = (new AnonymousNotifiable)->route('mail', 'x@example.com');
        $notifiable->notify(new KilnInvoicePaid);
        event(new NotificationFailed($notifiable, new KilnInvoicePaid, 'vonage', []));

        return 'ok';
    });

    $this->get('/notify')->assertOk();

    $notifications = collect($this->transport->spansOfType('notification'));
    expect($notifications->first(fn ($s) => $s['attrs']['kiln.notification.status'] === 'sent')['attrs'])->toMatchArray([
        'kiln.notification.class' => KilnInvoicePaid::class,
        'kiln.notification.channel' => 'mail',
        'kiln.notification.status' => 'sent',
    ]);

    $failed = $notifications->first(fn ($s) => $s['attrs']['kiln.notification.status'] === 'failed');
    expect($failed['attrs']['kiln.notification.channel'])->toBe('vonage')
        ->and($failed['status']['code'])->toBe(2);
});

it('records cache hit, miss, write and forget', function () {
    Route::get('/cache', function () {
        Cache::get('missing');
        Cache::put('present', 1, 60);
        Cache::get('present');
        Cache::forget('present');

        return 'ok';
    });

    $this->get('/cache')->assertOk();

    $ops = collect($this->transport->spansOfType('cache'))->map(fn ($s) => [$s['attrs']['kiln.cache.op'], $s['attrs']['kiln.cache.key'], $s['attrs']['kiln.cache.store']])->all();

    expect($ops)->toContain(['miss', 'missing', 'array'], ['write', 'present', 'array'], ['hit', 'present', 'array'], ['forget', 'present', 'array']);
});

function rerouteCommandEvents($app): void
{
    // Laravel skips re-routing Symfony console events while running unit tests.
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
}

it('records artisan commands as their own trace with exit code', function () {
    rerouteCommandEvents($this->app);
    Artisan::command('kiln:ok', fn () => 0);
    Artisan::command('kiln:bad', fn () => 3);

    Artisan::call('kiln:ok');
    Artisan::call('kiln:bad');

    $commands = collect($this->transport->spansOfType('command'))->keyBy(fn ($s) => $s['attrs']['kiln.command.name']);

    expect($commands['kiln:ok']['attrs']['process.exit.code'])->toBe(0)
        ->and($commands['kiln:ok'])->not->toHaveKey('parentSpanId')
        ->and($commands['kiln:ok']['status']['code'])->toBe(0)
        ->and($commands['kiln:bad']['attrs']['process.exit.code'])->toBe(3)
        ->and($commands['kiln:bad']['status']['code'])->toBe(2)
        ->and($commands['kiln:ok']['traceId'])->not->toBe($commands['kiln:bad']['traceId']);
});

it('records scheduled tasks run by schedule:run', function () {
    rerouteCommandEvents($this->app);
    $ran = false;
    $this->app->make(Schedule::class)->call(function () use (&$ran) {
        $ran = true;
    })->everyMinute()->name('kiln-cleanup');

    Artisan::call('schedule:run');
    expect($ran)->toBeTrue();

    $task = $this->transport->spansOfType('scheduled_task')[0];
    $command = collect($this->transport->spansOfType('command'))->first(fn ($s) => $s['attrs']['kiln.command.name'] === 'schedule:run');

    expect($task['attrs'])->toMatchArray([
        'kiln.event.type' => 'scheduled_task',
        'kiln.schedule.name' => 'kiln-cleanup',
        'kiln.schedule.expression' => '* * * * *',
        'kiln.schedule.status' => 'finished',
    ])->and($task['parentSpanId'])->toBe($command['spanId']);
});

it('records failed and skipped scheduled tasks as standalone traces', function () {
    $event = new ScheduleEvent($this->app->make(\Illuminate\Console\Scheduling\CacheEventMutex::class), 'php artisan kiln:nightly');
    $event->dailyAt('03:00');

    event(new \Illuminate\Console\Events\ScheduledTaskStarting($event));
    event(new ScheduledTaskFailed($event, new RuntimeException('nightly failed')));
    event(new ScheduledTaskSkipped($event));

    $tasks = collect($this->transport->spansOfType('scheduled_task'))->keyBy(fn ($s) => $s['attrs']['kiln.schedule.status']);

    expect($tasks['failed']['status']['code'])->toBe(2)
        ->and($tasks['failed']['attrs']['kiln.schedule.expression'])->toBe('0 3 * * *')
        ->and(collect($tasks['failed']['events'])->firstWhere('name', 'exception')['attrs']['kiln.exception.handled'])->toBeFalse()
        ->and($tasks['skipped']['attrs']['kiln.schedule.name'])->toBe('php artisan kiln:nightly');
});

it('ships logs as OTLP logs correlated with the active span', function () {
    Route::get('/log', function () {
        Log::warning('payment slow', ['order' => 7, 'password' => 'hunter2']);

        return 'ok';
    });

    $this->get('/log')->assertOk();

    $root = $this->transport->spansOfType('request')[0];
    $record = collect($this->transport->logRecords())->firstWhere('body.stringValue', 'payment slow');

    expect($record['severityNumber'])->toBe(13)
        ->and($record['severityText'])->toBe('WARNING')
        ->and($record['traceId'])->toBe($root['traceId'])
        ->and($record['spanId'])->toBe($root['spanId'])
        ->and($record['attrs']['kiln.context.order'])->toBe(7)
        ->and($record['attrs']['kiln.context.password'])->toBe('[redacted]');
});
