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

class FalakWelcomeMail extends Mailable
{
    public function build()
    {
        return $this->subject('Welcome')->html('<p>hi</p>');
    }
}

class FalakInvoicePaid extends Notification
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
        Mail::to(['a@example.com', 'b@example.com'])->cc('c@example.com')->send(new FalakWelcomeMail);

        return 'ok';
    });

    $this->get('/mail')->assertOk();

    $mail = $this->transport->spansOfType('mail')[0];
    expect($mail['kind'])->toBe(1)
        ->and($mail['attrs'])->toMatchArray([
            'falak.mail.class' => FalakWelcomeMail::class,
            'falak.mail.recipients_count' => 3,
            'falak.mail.mailer' => 'array',
        ]);
});

it('records notifications sent and failed', function () {
    Route::get('/notify', function () {
        $notifiable = (new AnonymousNotifiable)->route('mail', 'x@example.com');
        $notifiable->notify(new FalakInvoicePaid);
        event(new NotificationFailed($notifiable, new FalakInvoicePaid, 'vonage', []));

        return 'ok';
    });

    $this->get('/notify')->assertOk();

    $notifications = collect($this->transport->spansOfType('notification'));
    expect($notifications->first(fn ($s) => $s['attrs']['falak.notification.status'] === 'sent')['attrs'])->toMatchArray([
        'falak.notification.class' => FalakInvoicePaid::class,
        'falak.notification.channel' => 'mail',
        'falak.notification.status' => 'sent',
    ]);

    $failed = $notifications->first(fn ($s) => $s['attrs']['falak.notification.status'] === 'failed');
    expect($failed['attrs']['falak.notification.channel'])->toBe('vonage')
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

    $ops = collect($this->transport->spansOfType('cache'))->map(fn ($s) => [$s['attrs']['falak.cache.op'], $s['attrs']['falak.cache.key'], $s['attrs']['falak.cache.store']])->all();

    expect($ops)->toContain(['miss', 'missing', 'array'], ['write', 'present', 'array'], ['hit', 'present', 'array'], ['forget', 'present', 'array']);
});

function rerouteCommandEvents($app): void
{
    // Laravel skips re-routing Symfony console events while running unit tests.
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
}

it('records artisan commands as their own trace with exit code', function () {
    rerouteCommandEvents($this->app);
    Artisan::command('falak:ok', fn () => 0);
    Artisan::command('falak:bad', fn () => 3);

    Artisan::call('falak:ok');
    Artisan::call('falak:bad');

    $commands = collect($this->transport->spansOfType('command'))->keyBy(fn ($s) => $s['attrs']['falak.command.name']);

    expect($commands['falak:ok']['attrs']['process.exit.code'])->toBe(0)
        ->and($commands['falak:ok'])->not->toHaveKey('parentSpanId')
        ->and($commands['falak:ok']['status']['code'])->toBe(0)
        ->and($commands['falak:bad']['attrs']['process.exit.code'])->toBe(3)
        ->and($commands['falak:bad']['status']['code'])->toBe(2)
        ->and($commands['falak:ok']['traceId'])->not->toBe($commands['falak:bad']['traceId']);
});

it('records scheduled tasks run by schedule:run', function () {
    rerouteCommandEvents($this->app);
    $ran = false;
    $this->app->make(Schedule::class)->call(function () use (&$ran) {
        $ran = true;
    })->everyMinute()->name('falak-cleanup');

    Artisan::call('schedule:run');
    expect($ran)->toBeTrue();

    $task = $this->transport->spansOfType('scheduled_task')[0];
    $command = collect($this->transport->spansOfType('command'))->first(fn ($s) => $s['attrs']['falak.command.name'] === 'schedule:run');

    expect($task['attrs'])->toMatchArray([
        'falak.event.type' => 'scheduled_task',
        'falak.schedule.name' => 'falak-cleanup',
        'falak.schedule.expression' => '* * * * *',
        'falak.schedule.status' => 'finished',
    ])->and($task['parentSpanId'])->toBe($command['spanId']);
});

it('records failed and skipped scheduled tasks as standalone traces', function () {
    $event = new ScheduleEvent($this->app->make(\Illuminate\Console\Scheduling\CacheEventMutex::class), 'php artisan falak:nightly');
    $event->dailyAt('03:00');

    event(new \Illuminate\Console\Events\ScheduledTaskStarting($event));
    event(new ScheduledTaskFailed($event, new RuntimeException('nightly failed')));
    event(new ScheduledTaskSkipped($event));

    $tasks = collect($this->transport->spansOfType('scheduled_task'))->keyBy(fn ($s) => $s['attrs']['falak.schedule.status']);

    expect($tasks['failed']['status']['code'])->toBe(2)
        ->and($tasks['failed']['attrs']['falak.schedule.expression'])->toBe('0 3 * * *')
        ->and(collect($tasks['failed']['events'])->firstWhere('name', 'exception')['attrs']['falak.exception.handled'])->toBeFalse()
        ->and($tasks['skipped']['attrs']['falak.schedule.name'])->toBe('php artisan falak:nightly');
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
        ->and($record['attrs']['falak.context.order'])->toBe(7)
        ->and($record['attrs']['falak.context.password'])->toBe('[redacted]');
});
