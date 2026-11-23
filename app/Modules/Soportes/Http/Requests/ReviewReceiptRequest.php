<?php

namespace App\Modules\Soportes\Http\Requests;

use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the apply and reject forms. The route already checked
 * ReceiptPolicy::view (a reviewer of the receipt's company, or its
 * advisor); only reviewers (soportes.review) may decide, so anyone else gets
 * 403 before the fields are validated. Whether the receipt is still pending
 * is the controller's, so a reviewer who comes late gets a message, not 403.
 * A refused form goes back to the receipt's detail.
 */
abstract class ReviewReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('soportes.review') ?? false;
    }

    public function receipt(): Receipt
    {
        $receipt = $this->route('soporte');

        return $receipt instanceof Receipt ? $receipt : abort(404);
    }

    protected function getRedirectUrl(): string
    {
        return route('soportes.show', $this->receipt());
    }
}
