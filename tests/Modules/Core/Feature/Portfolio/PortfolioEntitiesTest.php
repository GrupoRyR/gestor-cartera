<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Database\Factories\ClientRecordFactory;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\ContactRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

it('keeps a receivables snapshot with its cutoff date and exact totals', function () {
    $load = PortfolioLoadRecord::factory()->create([
        'company' => CompanyCode::SDC,
        'cutoff_date' => '2026-10-15',
        'load_id' => 41,
        'source' => LoadSource::Manual,
        'invoice_count' => 3,
        'total' => '9876543210.54',
        'overdue' => '0.10',
    ])->refresh();

    expect($load->company)->toBe(CompanyCode::SDC)
        ->and($load->cutoff_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($load->cutoff_date->toDateString())->toBe('2026-10-15')
        ->and($load->load_id)->toBe(41)
        ->and($load->source)->toBe(LoadSource::Manual)
        ->and($load->invoice_count)->toBe(3)
        ->and($load->total)->toBe('9876543210.54')
        ->and($load->overdue)->toBe('0.10');
});

it('imports an Ingesta load only once', function () {
    PortfolioLoadRecord::factory()->create(['load_id' => 7]);

    expect(fn () => PortfolioLoadRecord::factory()->create(['load_id' => 7]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps the SAP data, the cached balance and the status of a client', function () {
    $manager = User::factory()->create();

    $client = ClientRecord::factory()->create([
        'company' => CompanyCode::SDC,
        'code' => 'C900123456',
        'name' => 'Ferretería El Tornillo S.A.S.',
        'tax_id' => '900123456-8',
        'group' => 'Ferreterías',
        'city' => 'Medellín',
        'payment_terms' => '30 días',
        'credit_limit' => '50000000.00',
        'advisor_code' => 12,
        'manager_user_id' => $manager->id,
        'status' => ClientStatus::Overdue,
        'status_changed_at' => '2026-10-15 07:30:00',
        'balance' => '1234567.89',
        'overdue' => '1000000.01',
        'max_days_overdue' => 44,
        'last_activity_at' => '2026-10-14 16:00:00',
    ])->refresh();

    expect($client->company)->toBe(CompanyCode::SDC)
        ->and($client->code)->toBe('C900123456')
        ->and($client->name)->toBe('Ferretería El Tornillo S.A.S.')
        ->and($client->tax_id)->toBe('900123456-8')
        ->and($client->group)->toBe('Ferreterías')
        ->and($client->city)->toBe('Medellín')
        ->and($client->payment_terms)->toBe('30 días')
        ->and($client->credit_limit)->toBe('50000000.00')
        ->and($client->advisor_code)->toBe(12)
        ->and($client->manager_user_id)->toBe($manager->id)
        ->and($client->status)->toBe(ClientStatus::Overdue)
        ->and($client->status_changed_at)->toEqual(CarbonImmutable::parse('2026-10-15 07:30:00'))
        ->and($client->balance)->toBe('1234567.89')
        ->and($client->overdue)->toBe('1000000.01')
        ->and($client->max_days_overdue)->toBe(44)
        ->and($client->last_activity_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('starts a client current, with nothing owed', function () {
    $client = ClientRecord::query()->create([
        'company' => CompanyCode::SGX,
        'code' => 'C1',
        'name' => 'Cliente nuevo',
        'tax_id' => '800197268-4',
    ]);

    expect($client->status)->toBe(ClientStatus::Current)
        ->and($client->balance)->toBe('0.00')
        ->and($client->overdue)->toBe('0.00')
        ->and($client->max_days_overdue)->toBe(0);

    $client->refresh();

    expect($client->status)->toBe(ClientStatus::Current)
        ->and($client->balance)->toBe('0.00')
        ->and($client->status_changed_at)->toBeNull()
        ->and($client->credit_limit)->toBeNull()
        ->and($client->advisor_code)->toBeNull()
        ->and($client->manager_user_id)->toBeNull();
});

it('allows one client per company and SAP code, also after a soft delete', function () {
    ClientRecord::factory()->create(['company' => CompanyCode::SGX, 'code' => 'C10']);
    ClientRecord::factory()->create(['company' => CompanyCode::SDC, 'code' => 'C10'])->delete();

    expect(fn () => ClientRecord::factory()->create(['company' => CompanyCode::SDC, 'code' => 'C10']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('soft-deletes clients', function () {
    $client = ClientRecord::factory()->create();

    $client->delete();

    expect(ClientRecord::query()->find($client->id))->toBeNull()
        ->and(ClientRecord::withTrashed()->find($client->id)?->trashed())->toBeTrue();

    $client->restore();

    expect(ClientRecord::query()->find($client->id))->not->toBeNull();
});

it('keeps each invoice of a snapshot with exact amounts', function () {
    $invoice = InvoiceRecord::factory()->create([
        'company' => CompanyCode::SGX,
        'document_number' => '104233',
        'document_type' => '13',
        'installment' => 2,
        'issued_at' => '2026-08-01',
        'due_at' => '2026-09-01',
        'amount' => '9876543210.54',
        'balance' => '-0.07',
        'days_overdue' => -3,
        'advisor_code' => 5,
    ])->refresh();

    expect($invoice->company)->toBe(CompanyCode::SGX)
        ->and($invoice->document_number)->toBe('104233')
        ->and($invoice->document_type)->toBe('13')
        ->and($invoice->installment)->toBe(2)
        ->and($invoice->issued_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($invoice->issued_at->toDateString())->toBe('2026-08-01')
        ->and($invoice->due_at->toDateString())->toBe('2026-09-01')
        ->and($invoice->amount)->toBe('9876543210.54')
        ->and($invoice->balance)->toBe('-0.07')
        ->and($invoice->days_overdue)->toBe(-3)
        ->and($invoice->advisor_code)->toBe(5);
});

it('links snapshots, clients, invoices and contacts', function () {
    $load = PortfolioLoadRecord::factory()->create();
    $client = ClientRecord::factory()->create();
    $other = ClientRecord::factory()->create();
    $invoices = InvoiceRecord::factory()->count(2)->for($load, 'portfolioLoad')->for($client, 'client')->create();
    InvoiceRecord::factory()->for($load, 'portfolioLoad')->for($other, 'client')->create();
    ContactRecord::factory()->for($client, 'client')->create(['name' => 'Ana Restrepo']);

    $load->load('invoices');
    $client->load(['invoices', 'contacts']);
    $invoice = InvoiceRecord::query()->with(['client', 'portfolioLoad'])->findOrFail($invoices[0]->id);

    expect($load->invoices)->toHaveCount(3)
        ->and($client->invoices->modelKeys())->toEqualCanonicalizing($invoices->modelKeys())
        ->and($client->contacts->pluck('name')->all())->toBe(['Ana Restrepo'])
        ->and($invoice->client->is($client))->toBeTrue()
        ->and($invoice->portfolioLoad->is($load))->toBeTrue();
});

it('removes the invoices of a snapshot together with it', function () {
    $load = PortfolioLoadRecord::factory()->create();
    $kept = InvoiceRecord::factory()->create();
    InvoiceRecord::factory()->count(2)->for($load, 'portfolioLoad')->create();

    $load->delete();

    expect(InvoiceRecord::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('never removes for good a client with snapshot history', function () {
    $client = ClientRecord::factory()->create();
    InvoiceRecord::factory()->for($client, 'client')->create();

    expect(fn () => $client->forceDelete())->toThrow(QueryException::class);
});

it('removes the contacts of a client removed for good', function () {
    $client = ClientRecord::factory()->create();
    ContactRecord::factory()->count(2)->for($client, 'client')->create();
    $kept = ContactRecord::factory()->create();

    $client->forceDelete();

    expect(ContactRecord::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('keeps the client when its manager is removed for good', function () {
    $manager = User::factory()->create();
    $client = ClientRecord::factory()->create(['manager_user_id' => $manager->id]);

    $manager->forceDelete();

    expect($client->refresh()->manager_user_id)->toBeNull();
});

it('keeps the contacts of a client', function () {
    $contact = ContactRecord::factory()->create([
        'name' => 'Ana Restrepo',
        'email' => 'ana.restrepo@example.com',
        'phone' => '+57 310 555 1234',
        'position' => 'Tesorería',
        'is_primary' => true,
    ])->refresh();

    expect($contact->name)->toBe('Ana Restrepo')
        ->and($contact->email)->toBe('ana.restrepo@example.com')
        ->and($contact->phone)->toBe('+57 310 555 1234')
        ->and($contact->position)->toBe('Tesorería')
        ->and($contact->is_primary)->toBeTrue()
        ->and($contact->source)->toBe('sap');
});

it('makes current Segurex clients with a valid NIT by default', function () {
    $client = ClientRecordFactory::new()->make();
    [$number, $checkDigit] = explode('-', $client->tax_id);

    expect($client->company)->toBe(CompanyCode::SGX)
        ->and($client->status)->toBe(ClientStatus::Current)
        ->and($client->code)->toBe("C{$number}")
        ->and($number)->toMatch('/^[89]\d{8}$/')
        ->and((int) $checkDigit)->toBe(ClientRecordFactory::checkDigit($number))
        ->and($client->name)->toEndWith('S.A.S.');
});

it('computes the DIAN check digit of a NIT', function (string $number, int $checkDigit) {
    expect(ClientRecordFactory::checkDigit($number))->toBe($checkDigit);
})->with([
    'Bancolombia' => ['890903938', 8],
    'DIAN' => ['800197268', 4],
    'Davivienda' => ['860034313', 7],
]);
