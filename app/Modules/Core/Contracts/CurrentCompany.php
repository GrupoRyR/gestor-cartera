<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\CompanyNotAvailable;

/**
 * The company the authenticated user is working in: SGX, SDC or the group
 * view, which covers every company the user may see at once.
 *
 * Implemented by Core (phase 0b); tests use Tests\Fakes\FakeCurrentCompany.
 *
 * The choice lives in the user's session and the company selector changes
 * it. The brand comes from the active company, never from the domain of the
 * user's e-mail address: the group view and guests (and users without a
 * role) get the neutral palette. Companies are listed in CompanyCode case
 * order.
 */
interface CurrentCompany
{
    /**
     * The active company; null in the group view and for guests.
     */
    public function active(): ?Company;

    /**
     * Whether the user is in the group view. Always false for guests.
     */
    public function isGroup(): bool;

    /**
     * Companies the current view covers: the active one, or every available
     * company in the group view; empty for guests. Queries that are not
     * given explicit companies are limited to these.
     *
     * @return list<Company>
     */
    public function scope(): array;

    /**
     * Companies the user may select (roles are granted per company); empty
     * for guests.
     *
     * @return list<Company>
     */
    public function available(): array;

    /**
     * Changes the active company for the rest of the session; null selects
     * the group view, which needs at least two available companies.
     *
     * @throws CompanyNotAvailable When the company, or the group view, is not available to the user.
     */
    public function switchTo(?CompanyCode $code): void;

    /**
     * Palette of the active company; neutral in the group view and for
     * guests.
     */
    public function palette(): BrandPalette;

    /**
     * Any company with its parameters, whatever the user may see; for
     * scheduled jobs, queues and e-mail templates.
     */
    public function of(CompanyCode $code): Company;
}
