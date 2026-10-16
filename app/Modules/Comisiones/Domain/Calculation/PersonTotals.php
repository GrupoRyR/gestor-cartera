<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\PersonRef;

/**
 * What each person is paid (§5.7): the sum of every role, the "valor pago
 * nómina". The roles are, in this order:
 *
 *   - every regular or separate code with activity in the period ("Código
 *     14 – Mónica Rivera"), even when it pays zero; a code without activity
 *     and nothing to pay is left out, and a control code (80, 100) is listed
 *     only when it has something to pay;
 *   - every derived commission ("Sergio Caballero (venta)");
 *   - every director team paid as a role of its own ("Director Distribución").
 *     A team paid with its associated code is already in that code's total.
 *
 * A code without person is paid as its own person ("codigo-17"). A person
 * left without roles is not listed.
 */
final class PersonTotals
{
    /**
     * @param  array<int, CodeSettlement>  $codes
     * @param  list<DerivedSettlement>  $derived
     * @param  list<DirectorSettlement>  $directors
     * @return list<PersonSettlement> By person name, in natural order.
     */
    public static function calculate(array $codes, array $derived, array $directors): array
    {
        /** @var array<string, PersonRef> $people */
        $people = [];

        /** @var array<string, list<PersonRole>> $roles */
        $roles = [];

        foreach (self::roles($codes, $derived, $directors) as [$person, $role]) {
            $people[$person->id] ??= $person;
            $roles[$person->id][] = $role;
        }

        $settlements = [];

        foreach ($people as $id => $person) {
            $settlements[] = new PersonSettlement(
                $person,
                $roles[$id],
                Money::sum(array_map(fn (PersonRole $role) => $role->amount, $roles[$id])),
            );
        }

        usort($settlements, fn (PersonSettlement $a, PersonSettlement $b) => strnatcasecmp($a->person->name, $b->person->name)
            ?: strcmp($a->person->id, $b->person->id));

        return $settlements;
    }

    /**
     * @param  array<int, CodeSettlement>  $codes
     * @param  list<DerivedSettlement>  $derived
     * @param  list<DirectorSettlement>  $directors
     * @return list<array{PersonRef, PersonRole}>
     */
    private static function roles(array $codes, array $derived, array $directors): array
    {
        $roles = [];

        foreach ($codes as $code => $settlement) {
            $idle = $settlement->rule->scope === CodeScope::Control || ! $settlement->hasActivity();

            if ($idle && $settlement->total->isZero()) {
                continue;
            }

            $roles[] = [
                $settlement->rule->personRef(),
                new PersonRole(RoleKind::Code, (string) $code, self::codeLabel($settlement->rule), $settlement->total),
            ];
        }

        foreach ($derived as $settlement) {
            $roles[] = [
                $settlement->rule->person,
                new PersonRole(RoleKind::Derived, $settlement->rule->key, $settlement->rule->label, $settlement->commission),
            ];
        }

        foreach ($directors as $settlement) {
            if ($settlement->paidWithCode !== null) {
                continue;
            }

            $roles[] = [
                $settlement->team->director,
                new PersonRole(RoleKind::Director, $settlement->team->key, "Director {$settlement->team->channel}", $settlement->total),
            ];
        }

        return $roles;
    }

    /** "Código 14 – Mónica Rivera"; just "Código 17" when the code is named after itself. */
    private static function codeLabel(SellerCodeRule $rule): string
    {
        $code = "Código {$rule->code}";

        return $rule->name === $code ? $code : "{$code} – {$rule->name}";
    }
}
