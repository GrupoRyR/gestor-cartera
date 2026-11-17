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

    /**
     * Every permission of every module (except the ones a module seeder
     * excludes) and both companies whole. Assigned and removed only from the
     * console (core:superadmin), never from the team screen.
     */
    case SuperAdmin = 'superadmin';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Superadministrador',
            self::Admin => 'Administrador',
            self::PortfolioManager => 'Gerente de cartera',
            self::PortfolioAnalyst => 'Analista de cartera',
            self::SalesAdvisor => 'Asesor comercial',
            self::SalesManager => 'Gerente comercial',
            self::Treasury => 'Tesorería',
        };
    }

    /**
     * The roles the team screen offers and accepts, as value => label: every
     * role but SuperAdmin, which only the console assigns.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case !== self::SuperAdmin) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }
}
