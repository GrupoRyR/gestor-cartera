<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\CompanyNotAvailable;
use App\Modules\Core\Contracts\Exceptions\ContractException;
use App\Modules\Core\Contracts\Exceptions\InvalidBatch;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;

it('names the company that is not available', function () {
    $exception = CompanyNotAvailable::forCompany(CompanyCode::SDC);

    expect($exception)->toBeInstanceOf(ContractException::class)
        ->and($exception->company)->toBe(CompanyCode::SDC)
        ->and($exception->getMessage())->toBe('Company SDC is not available to the current user.');
});

it('explains that the group view is not available', function () {
    $exception = CompanyNotAvailable::groupView();

    expect($exception->company)->toBeNull()
        ->and($exception->getMessage())->toBe('The group view is not available to the current user.');
});

it('names the record that was not found', function () {
    $exception = RecordNotFound::for('commitment', 42);

    expect($exception)->toBeInstanceOf(ContractException::class)
        ->and($exception->type)->toBe('commitment')
        ->and($exception->id)->toBe(42)
        ->and($exception->getMessage())->toBe('No commitment with id 42 exists in the current scope.');
});

it('accepts string ids for records that are not found', function () {
    expect(RecordNotFound::for('inbound mail', 'AAMkAD')->getMessage())
        ->toBe('No inbound mail with id AAMkAD exists in the current scope.');
});

it('carries the validation errors of a rejected batch', function () {
    $exception = new InvalidBatch('The batch has invalid rows.', [
        'rows.3.saldo' => ['The saldo must be a number.'],
    ]);

    expect($exception)->toBeInstanceOf(ContractException::class)
        ->and($exception->getMessage())->toBe('The batch has invalid rows.')
        ->and($exception->errors())->toBe(['rows.3.saldo' => ['The saldo must be a number.']]);
});

it('has no errors unless they are given', function () {
    expect((new InvalidBatch('Unknown dataset.'))->errors())->toBe([]);
});

it('keeps the cause of a mail that was not sent', function () {
    $cause = new RuntimeException('Graph returned 403');
    $exception = new MailNotSent('The mail could not be sent.', previous: $cause);

    expect($exception)->toBeInstanceOf(ContractException::class)
        ->and($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getPrevious())->toBe($cause);
});
