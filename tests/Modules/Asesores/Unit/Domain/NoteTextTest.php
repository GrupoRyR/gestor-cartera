<?php

use App\Modules\Asesores\Domain\Activity\NoteText;

it('keeps a short one-line note whole, as the summary', function () {
    $note = NoteText::split('Hablé con tesorería, confirman pago el viernes.');

    expect($note->summary)->toBe('Hablé con tesorería, confirman pago el viernes.')
        ->and($note->details)->toBeNull();
});

it('takes the first line as the summary and keeps the whole text as the details', function () {
    $note = NoteText::split("Llamé a tesorería\nDicen que el pago sale el viernes.\nVolver a llamar el lunes.");

    expect($note->summary)->toBe('Llamé a tesorería')
        ->and($note->details)->toBe("Llamé a tesorería\nDicen que el pago sale el viernes.\nVolver a llamar el lunes.");
});

it('cuts a long first line for the summary and keeps the whole text', function () {
    $text = str_repeat('palabra ', 40).'final';
    $note = NoteText::split($text);

    expect(mb_strlen($note->summary))->toBeLessThanOrEqual(150)
        ->and($note->summary)->toEndWith('…')
        ->and($note->details)->toBe($text);
});

it('trims the text and ignores blank lines in front', function () {
    $note = NoteText::split("\n\n   Nota con espacios   \n");

    expect($note->summary)->toBe('Nota con espacios')
        ->and($note->details)->toBeNull();
});

it('writes the line breaks of every system the same way', function () {
    $note = NoteText::split("Primera\r\nSegunda");

    expect($note->summary)->toBe('Primera')
        ->and($note->details)->toBe("Primera\nSegunda");
});

it('has no summary for an empty text', function () {
    expect(NoteText::split("  \n ")->summary)->toBe('');
});
