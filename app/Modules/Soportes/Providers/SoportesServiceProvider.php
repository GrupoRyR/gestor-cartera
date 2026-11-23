<?php

namespace App\Modules\Soportes\Providers;

use App\Models\User;
use App\Modules\Soportes\Application\Inbox\VisibleReceipts;
use App\Modules\Soportes\Http\Policies\ReceiptPolicy;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Support\Facades\Gate;

/**
 * Soportes: the inbox of payment receipts. The advisor or the portfolio team
 * uploads a client's receipt against its overdue invoices; the analyst
 * applies the payment in SAP and marks the receipt applied or rejected.
 *
 * Web routes: /soportes/*, named soportes.* (routes/web.php).
 * Owner: the "Soportes" session.
 */
final class SoportesServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        Gate::policy(Receipt::class, ReceiptPolicy::class);
    }

    /**
     * "Soportes de pago" closes the Cartera group (its entries use 10 to 40),
     * so / still opens the dashboard of the portfolio roles and the
     * advisor's summary. The badge counts the pending receipts of the
     * companies in view, only for reviewers.
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(
            new NavItem(
                key: 'soportes.bandeja',
                label: __('soportes::bandeja.menu'),
                route: 'soportes.index',
                icon: 'document',
                permission: 'soportes.view',
                group: 'Cartera',
                order: 45,
                activePattern: 'soportes.*',
                badge: function (): ?int {
                    $user = auth()->user();

                    return $user instanceof User ? $this->app->make(VisibleReceipts::class)->pendingCount($user) : null;
                },
            ),
        );
    }
}
