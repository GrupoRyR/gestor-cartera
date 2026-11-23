<?php

namespace App\Modules\Soportes\Providers;

use App\Support\Modules\ModuleServiceProvider;

/**
 * Soportes: the inbox of payment receipts. The advisor or the portfolio team
 * uploads a client's receipt against its overdue invoices; the analyst
 * applies the payment in SAP and marks the receipt applied or rejected.
 *
 * Web routes: /soportes/*, named soportes.* (they arrive with the upload and
 * the inbox; this base has none, and the provider loads route files only
 * when they exist).
 * Owner: the "Soportes" session.
 */
final class SoportesServiceProvider extends ModuleServiceProvider {}
