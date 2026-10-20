<?php

namespace App\Modules\Gerencia\Http\Requests;

use App\Modules\Gerencia\Domain\Period\PeriodKey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /gerencia: the optional `periodo` of the dashboard. Access is decided
 * by the route middleware (`can:gerencia.view`).
 */
final class BoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Nothing is rejected: an unknown or malformed `periodo` shows the
     * default period instead of an error page, so an old link keeps working.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The period asked for; the current month when the value is missing,
     * unknown or not a plain text.
     */
    public function period(): PeriodKey
    {
        $value = $this->query('periodo');

        return PeriodKey::fromRequest(is_string($value) ? $value : null);
    }
}
