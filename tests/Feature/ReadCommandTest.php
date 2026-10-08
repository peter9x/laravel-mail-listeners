<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Models\MailMessage;
use Mupy\MailListeners\Testing\FakeConnector;
use Mupy\MailListeners\Tests\Support\Events\SupplierEmailReceived;

beforeEach(function (): void {
    Event::fake([SupplierEmailReceived::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
    $this->connector = FakeConnector::swap();
    $this->account = MailAccount::factory()->withEvents(['supplier'])->create([
        'email' => 'fornecedores@onevetgroup.pt',
        'last_polled_at' => '2026-10-06 11:55:00',
        'last_received_at' => '2026-10-06 11:50:00',
    ]);
});

it('reads a configured account within whole days, firing the events of the emails not read yet', function (): void {
    $alreadyRead = FakeConnector::makeEmail($this->account, ['subject' => 'Já lido', 'receivedAt' => CarbonImmutable::parse('2026-01-10 10:00')]);
    $this->connector->messages = [
        $alreadyRead,
        FakeConnector::makeEmail($this->account, ['subject' => 'Primeiro dia', 'receivedAt' => CarbonImmutable::parse('2026-01-01 00:00')]),
        FakeConnector::makeEmail($this->account, ['subject' => 'Último dia', 'receivedAt' => CarbonImmutable::parse('2026-01-31 23:59')]),
        FakeConnector::makeEmail($this->account, ['subject' => 'Fora do período', 'receivedAt' => CarbonImmutable::parse('2026-02-01 00:00')]),
    ];
    MailMessage::factory()->for($this->account, 'account')->create(['dedup_key' => $alreadyRead->dedupKey()]);

    $this->artisan('mail-listeners:read', ['email' => 'Fornecedores@OneVetGroup.pt', '--from' => '01-01-2026', '--to' => '31-01-2026'])
        ->expectsOutputToContain('3 emails found: 2 new (events fired), 1 already read.')
        ->assertSuccessful();

    expect($this->connector->rangesRequested[0][0]->toDateTimeString())->toBe('2026-01-01 00:00:00')
        ->and($this->connector->rangesRequested[0][1]->toDateTimeString())->toBe('2026-02-01 00:00:00');
    Event::assertDispatchedTimes(SupplierEmailReceived::class, 2);
    Event::assertDispatched(SupplierEmailReceived::class, fn (SupplierEmailReceived $event): bool => $event->email->subject === 'Último dia');
});

it('leaves the polling cursor of the account untouched', function (): void {
    $this->connector->messages = [FakeConnector::makeEmail($this->account, ['receivedAt' => CarbonImmutable::parse('2026-01-15 10:00')])];

    $this->artisan('mail-listeners:read', ['email' => 'fornecedores@onevetgroup.pt', '--from' => '01-01-2026', '--to' => '31-01-2026'])->assertSuccessful();

    $this->account->refresh();
    expect($this->account->last_received_at->toDateTimeString())->toBe('2026-10-06 11:50:00')
        ->and($this->account->last_polled_at->toDateTimeString())->toBe('2026-10-06 11:55:00');
});

it('accepts a time of day and defaults to the last 24 hours', function (): void {
    $this->artisan('mail-listeners:read', ['email' => 'fornecedores@onevetgroup.pt', '--from' => '05-01-2026 08:30', '--to' => '05-01-2026 18:00'])->assertSuccessful();
    $this->artisan('mail-listeners:read', ['email' => 'fornecedores@onevetgroup.pt'])->assertSuccessful();

    expect(array_map(fn (array $range): array => [$range[0]->toDateTimeString(), $range[1]->toDateTimeString()], $this->connector->rangesRequested))->toBe([
        ['2026-01-05 08:30:00', '2026-01-05 18:00:00'],
        ['2026-10-05 12:00:00', '2026-10-06 12:00:00'],
    ]);
});

it('only reads emails configured as accounts', function (): void {
    $this->artisan('mail-listeners:read', ['email' => 'outro@onevetgroup.pt', '--from' => '01-01-2026', '--to' => '31-01-2026'])
        ->expectsOutputToContain('is not configured')
        ->assertFailed();

    expect($this->connector->rangesRequested)->toBe([]);
});

it('rejects invalid periods', function (array $options): void {
    $this->artisan('mail-listeners:read', ['email' => 'fornecedores@onevetgroup.pt', ...$options])->assertFailed();

    expect($this->connector->rangesRequested)->toBe([]);
})->with([
    'invalid date' => [['--from' => 'not a date']],
    'start after end' => [['--from' => '31-01-2026', '--to' => '01-01-2026']],
]);

it('reports when the account cannot be read', function (): void {
    $this->connector->failWith = new RuntimeException('Access denied');

    $this->artisan('mail-listeners:read', ['email' => 'fornecedores@onevetgroup.pt', '--from' => '01-01-2026', '--to' => '31-01-2026'])
        ->expectsOutputToContain('Access denied')
        ->assertFailed();
});

it('shows the progress while reading, with a plain line every 25 emails when the output is not a terminal', function (): void {
    $this->connector->messages = array_map(
        fn (int $i): InboundEmail => FakeConnector::makeEmail($this->account, ['receivedAt' => CarbonImmutable::parse('2026-01-01 08:00')->addMinutes($i)]),
        range(1, 30),
    );

    $this->artisan('mail-listeners:read', ['email' => 'fornecedores@onevetgroup.pt', '--from' => '01-01-2026', '--to' => '31-01-2026'])
        ->expectsOutputToContain('Connecting to fornecedores@onevetgroup.pt and searching messages...')
        ->expectsOutputToContain('25 emails read (25 new)...')
        ->expectsOutputToContain('30 emails read (30 new).')
        ->expectsOutputToContain('30 emails found: 30 new (events fired), 0 already read.')
        ->assertSuccessful();
});

it('lists every new email when verbose', function (): void {
    $alreadyRead = FakeConnector::makeEmail($this->account, ['subject' => 'Já lido', 'receivedAt' => CarbonImmutable::parse('2026-01-10 10:00')]);
    $this->connector->messages = [
        $alreadyRead,
        FakeConnector::makeEmail($this->account, ['subject' => 'Extracto Via Verde', 'receivedAt' => CarbonImmutable::parse('2026-01-14 09:12')]),
    ];
    MailMessage::factory()->for($this->account, 'account')->create(['dedup_key' => $alreadyRead->dedupKey()]);

    $this->artisan('mail-listeners:read', ['email' => 'fornecedores@onevetgroup.pt', '--from' => '01-01-2026', '--to' => '31-01-2026', '-v' => true])
        ->expectsOutputToContain('+ 14-01-2026 09:12  supplier@example.com  Extracto Via Verde')
        ->doesntExpectOutputToContain('Já lido')
        ->assertSuccessful();
});

it('tells how far it got and how to resume when the account fails while reading', function (): void {
    $this->connector->messages = array_map(
        fn (int $day): InboundEmail => FakeConnector::makeEmail($this->account, ['receivedAt' => CarbonImmutable::parse("2026-01-{$day} 09:12")]),
        range(10, 14),
    );
    $this->connector->failAfter = 3;
    $this->connector->failWith = new RuntimeException('Connection lost');

    $this->artisan('mail-listeners:read', ['email' => 'fornecedores@onevetgroup.pt', '--from' => '01-01-2026', '--to' => '31-01-2026'])
        ->expectsOutputToContain('The mail account could not be read: Connection lost')
        ->expectsOutputToContain('Read before the failure: 3 emails found, 3 new (events fired), 0 already read.')
        ->expectsOutputToContain('Last email processed: received at 12-01-2026 09:12.')
        ->expectsOutputToContain('Resume with: php artisan mail-listeners:read fornecedores@onevetgroup.pt --from="12-01-2026 09:12" --to="01-02-2026 00:00"')
        ->assertFailed();

    Event::assertDispatchedTimes(SupplierEmailReceived::class, 3);
});
