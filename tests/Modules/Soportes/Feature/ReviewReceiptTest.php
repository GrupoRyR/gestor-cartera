<?php

use App\Models\User;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Soportes\Application\Review\ReviewReceipt;
use App\Modules\Soportes\Database\Seeders\SoportesPermissionSeeder;
use App\Modules\Soportes\Domain\Exceptions\InvalidReceiptTransition;
use App\Modules\Soportes\Domain\ReceiptStatus;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\Make;
use Tests\Modules\Soportes\Support\SoportesUsers;

beforeEach(function () {
    $this->seed(SoportesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->advisor = SoportesUsers::advisor();
    $this->analyst = SoportesUsers::analyst();
    $this->client = Make::client(name: 'Ferretería El Tornillo SAS', advisor: Make::advisor(code: 10));
    $this->fakes->cartera->addClient(Make::clientDetail($this->client));
});

/**
 * A receipt of the test client uploaded by the test advisor; pending unless
 * $state names a factory state ("applied", "rejected").
 */
function soportesReviewReceipt(?string $state = null): Receipt
{
    $factory = Receipt::factory()->forClient(test()->client)->uploadedBy(test()->advisor);

    return ($state === null ? $factory : $factory->{$state}())->create();
}

/**
 * @param  array<string, mixed>  $fields
 */
function soportesDecide(User $user, Receipt $receipt, string $action, array $fields = []): TestResponse
{
    return test()->actingAs($user)->from(route('soportes.show', $receipt))->patch("/soportes/{$receipt->id}/{$action}", $fields);
}

it('serves the review actions under /soportes', function () {
    $receipt = soportesReviewReceipt();

    expect(route('soportes.apply', $receipt, false))->toBe("/soportes/{$receipt->id}/aplicar")
        ->and(route('soportes.reject', $receipt, false))->toBe("/soportes/{$receipt->id}/rechazar");
});

describe('applying', function () {
    it('marks the receipt applied with the cash receipt number, and audits it', function () {
        $receipt = soportesReviewReceipt();

        soportesDecide($this->analyst, $receipt, 'aplicar', ['recibo' => ' RC-55012 '])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('soportes.show', $receipt))
            ->assertSessionHas('status', 'Soporte marcado como aplicado.');

        $receipt->refresh();

        expect($receipt->status)->toBe(ReceiptStatus::Applied)
            ->and($receipt->receipt_number)->toBe('RC-55012')
            ->and($receipt->rejection_reason)->toBeNull()
            ->and($receipt->reviewed_by)->toBe($this->analyst->id)
            ->and($receipt->reviewed_at?->equalTo(now()))->toBeTrue();

        $this->fakes->audit->assertRecorded('soportes.receipt.applied', fn (AuditEntry $entry): bool => $entry->subjectType === 'soporte'
            && $entry->subjectId === $receipt->id
            && $entry->changes['status'] === ['old' => 'pendiente', 'new' => 'aplicado']
            && $entry->changes['receipt_number'] === ['old' => null, 'new' => 'RC-55012']);
    });

    it('lets the manager apply it without a cash receipt number', function () {
        $receipt = soportesReviewReceipt();

        soportesDecide(SoportesUsers::create(Role::PortfolioManager), $receipt, 'aplicar', ['recibo' => ''])->assertSessionHasNoErrors();

        expect($receipt->refresh()->status)->toBe(ReceiptStatus::Applied)
            ->and($receipt->receipt_number)->toBeNull();
    });

    it('refuses a cash receipt number over 60 characters', function () {
        $receipt = soportesReviewReceipt();

        soportesDecide($this->analyst, $receipt, 'aplicar', ['recibo' => str_repeat('9', 61)])
            ->assertRedirect(route('soportes.show', $receipt))
            ->assertSessionHasErrors('recibo');

        expect($receipt->refresh()->status)->toBe(ReceiptStatus::Pending);
    });
});

describe('rejecting', function () {
    it('marks the receipt rejected with the reason, audits it and the advisor sees why', function () {
        $receipt = soportesReviewReceipt();

        soportesDecide($this->analyst, $receipt, 'rechazar', ['motivo' => 'El valor no coincide con el extracto.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('soportes.show', $receipt))
            ->assertSessionHas('status', 'Soporte rechazado. El asesor verá el motivo.');

        expect($receipt->refresh()->status)->toBe(ReceiptStatus::Rejected)
            ->and($receipt->rejection_reason)->toBe('El valor no coincide con el extracto.')
            ->and($receipt->receipt_number)->toBeNull()
            ->and($receipt->reviewed_by)->toBe($this->analyst->id);

        $this->fakes->audit->assertRecorded('soportes.receipt.rejected', fn (AuditEntry $entry): bool => $entry->subjectId === $receipt->id
            && $entry->changes['status'] === ['old' => 'pendiente', 'new' => 'rechazado']
            && $entry->changes['rejection_reason'] === ['old' => null, 'new' => 'El valor no coincide con el extracto.']);

        $this->fakes->cartera->restrictToAdvisor(10);

        $this->actingAs($this->advisor)->get('/soportes?estado=rechazado')->assertSee('El valor no coincide con el extracto.');
        $this->actingAs($this->advisor)->get('/soportes/'.$receipt->id)->assertSee('El valor no coincide con el extracto.');
    });

    it('requires a reason of up to 500 characters', function (string $reason) {
        $receipt = soportesReviewReceipt();

        soportesDecide($this->analyst, $receipt, 'rechazar', ['motivo' => $reason])
            ->assertRedirect(route('soportes.show', $receipt))
            ->assertSessionHasErrors('motivo');

        expect($receipt->refresh()->status)->toBe(ReceiptStatus::Pending);
        $this->fakes->audit->assertNothingRecorded();
    })->with(['empty' => [''], 'blank' => ['   '], 'too long' => [str_repeat('a', 501)]]);
});

describe('who may review', function () {
    it('keeps out the advisor, treasury and the superadmin', function (Closure $user, string $action) {
        $receipt = soportesReviewReceipt();
        $this->fakes->cartera->restrictToAdvisor(10);

        soportesDecide($user(), $receipt, $action, ['recibo' => '1', 'motivo' => 'No'])->assertForbidden();

        expect($receipt->refresh()->status)->toBe(ReceiptStatus::Pending);
        $this->fakes->audit->assertNothingRecorded();
    })->with([
        'the advisor of the client' => [fn () => test()->advisor],
        'treasury' => [fn () => SoportesUsers::create(Role::Treasury)],
        'the superadmin' => [fn () => SoportesUsers::create(Role::SuperAdmin)],
    ])->with(['aplicar', 'rechazar']);

    it('keeps out a reviewer of another company', function () {
        $receipt = soportesReviewReceipt();
        app()->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SDC, [CompanyCode::SDC]));

        soportesDecide($this->analyst, $receipt, 'aplicar')->assertForbidden();

        expect($receipt->refresh()->status)->toBe(ReceiptStatus::Pending);
    });
});

describe('final states', function () {
    it('refuses to decide again on an applied or rejected receipt, saying it was already reviewed', function (string $state, string $action) {
        $receipt = soportesReviewReceipt($state);
        $before = $receipt->refresh()->only(['status', 'receipt_number', 'rejection_reason', 'reviewed_by']);

        soportesDecide($this->analyst, $receipt, $action, ['recibo' => '77', 'motivo' => 'Otro motivo'])
            ->assertRedirect(route('soportes.show', $receipt))
            ->assertSessionHas('error', 'Este soporte ya fue revisado, así que no se cambió nada.');

        expect($receipt->refresh()->only(['status', 'receipt_number', 'rejection_reason', 'reviewed_by']))->toEqual($before);
        $this->fakes->audit->assertNothingRecorded();
    })->with(['applied', 'rejected'])->with(['aplicar', 'rechazar']);

    it('lets only the first of two reviewers who opened it pending decide', function () {
        $first = soportesReviewReceipt();
        $stale = Receipt::query()->findOrFail($first->id);
        $review = app(ReviewReceipt::class);

        $review->apply($first, $this->analyst, 'RC-1');

        expect(fn () => $review->reject($stale, SoportesUsers::create(Role::PortfolioManager), 'Duplicado'))
            ->toThrow(InvalidReceiptTransition::class);

        expect($first->refresh()->status)->toBe(ReceiptStatus::Applied)
            ->and($first->receipt_number)->toBe('RC-1')
            ->and($first->rejection_reason)->toBeNull()
            ->and($this->fakes->audit->entries())->toHaveCount(1);
    });
});

describe('the detail page', function () {
    it('offers the reviewer the apply and reject forms while the receipt is pending', function () {
        $receipt = soportesReviewReceipt();

        $this->actingAs($this->analyst)->get('/soportes/'.$receipt->id)
            ->assertOk()
            ->assertSee(route('soportes.apply', $receipt))
            ->assertSee(route('soportes.reject', $receipt))
            ->assertSee('name="recibo"', false)
            ->assertSee('name="motivo"', false);
    });

    it('offers no review forms to the advisor, nor on a final receipt', function () {
        $pending = soportesReviewReceipt();
        $applied = soportesReviewReceipt('applied');
        $this->fakes->cartera->restrictToAdvisor(10);

        $this->actingAs($this->advisor)->get('/soportes/'.$pending->id)->assertOk()->assertDontSee(route('soportes.apply', $pending));
        $this->actingAs($this->analyst)->get('/soportes/'.$applied->id)->assertOk()->assertDontSee(route('soportes.reject', $applied));
    });
});

it('takes a decided receipt off the menu badge', function () {
    $receipt = soportesReviewReceipt();
    soportesReviewReceipt();
    $this->actingAs($this->analyst);
    $entry = collect(app(NavRegistry::class)->visibleTo($this->analyst))->firstWhere('key', 'soportes.bandeja');

    expect($entry?->badgeCount())->toBe(2);

    soportesDecide($this->analyst, $receipt, 'rechazar', ['motivo' => 'Comprobante ilegible.']);

    expect($entry?->badgeCount())->toBe(1);
});
