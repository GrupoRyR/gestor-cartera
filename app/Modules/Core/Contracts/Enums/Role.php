<?php

namespace App\Modules\Core\Contracts\Enums;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * User roles (docs/prompt-gestor-cartera.md, Part A.5).
 *
 * The value is the role name registered in spatie/laravel-permission. Roles
 * are granted per company; a user without a role sees a neutral screen that
 * asks them to request access.
 */
enum Role: string
{
    use HasOptions;

    /** IT: users, parameters and mailboxes. */
    case Admin = 'admin';

    /** Sees everything in both companies, the management dashboard and commissions. */
    case PortfolioManager = 'gerente_cartera';

    /** Works the whole portfolio: every client, every commitment, replies, collections. */
    case PortfolioAnalyst = 'analista_cartera';

    /** Sales advisor linked to a SAP sales employee (OSLP.SlpCode): sees only their own clients. */
    case SalesAdvisor = 'asesor';

    /** Sales manager: sees the clients of the advisors in their team. */
    case SalesManager = 'gerente_comercial';

    /** Treasury: delivers the bank movements (future role). */
    case Treasury = 'tesoreria';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::PortfolioManager => 'Gerente de cartera',
            self::PortfolioAnalyst => 'Analista de cartera',
            self::SalesAdvisor => 'Asesor comercial',
            self::SalesManager => 'Gerente comercial',
            self::Treasury => 'Tesorería',
        };
    }
}
