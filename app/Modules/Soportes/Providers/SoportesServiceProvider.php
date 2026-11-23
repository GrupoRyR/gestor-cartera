<?php

namespace App\Modules\Soportes\Providers;

use App\Modules\Soportes\Http\Policies\ReceiptPolicy;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Soportes: the inbox of payment receipts. The advisor or the portfolio team
 * uploads a client's receipt against its overdue invoices; the analyst
 * applies the payment in SAP and marks the receipt applied or rejected.
 *
 * Web routes: /soportes/*, named soportes.* (routes/web.php: the upload;
 * the inbox and its menu entry arrive with the review).
 * Owner: the "Soportes" session.
 */
final class SoportesServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(Receipt::class, ReceiptPolicy::class);
    }
}
