<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Models\User;
use App\Modules\Comisiones\Application\ApproveSettlement;
use App\Modules\Comisiones\Application\CalculateSettlement;
use App\Modules\Comisiones\Application\CloseSettlement;
use App\Modules\Comisiones\Application\RegisterComplementarySettlement;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Settlement\UnresolvedAlerts;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Requests\ApproveSettlementRequest;
use App\Modules\Comisiones\Http\Requests\ComplementarySettlementRequest;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use App\Modules\Comisiones\Http\Support\SettlementErrors;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Mail\ApprovalMail;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\ApprovalGuard;
use App\Modules\Comisiones\Infrastructure\Settlement\ApprovalRefused;
use App\Modules\Comisiones\Infrastructure\Settlement\CorruptSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Modules\Comisiones\Infrastructure\Settlement\InputChanges;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Throwable;

/**
 * The steps of the monthly flow (§6 steps 4–7), each a POST under
 * /comisiones/periodos/{periodo}:
 *
 * - calcular (comisiones.calculate): first calculation or recalculation;
 * - aprobar (comisiones.approve): ApprovalGuard (§10), then the approval of
 *   the reviewed revision with a justification per blocking alert (§9.7);
 *   once stored, ApprovalMail sends the consolidated PDF, and a mail that
 *   fails only adds a warning: the approval stands;
 * - cerrar (comisiones.close): freezes the approved version;
 * - complementaria (comisiones.calculate): a closed period settled again as
 *   a new version, with its reason.
 *
 * Users go to the engine as their e-mail. Each step is audited as
 * comisiones.settlement.<verb> and answers with a Spanish flash message;
 * a refused step (README "Map the exceptions") comes back with the reason.
 */
final class SettlementActionController
{
    public function __construct(
        private readonly EloquentSettlements $settlements,
        private readonly AuditTrail $audit,
        private readonly InputChanges $changes,
    ) {}

    public function calculate(Request $request, CalculateSettlement $calculate, string $periodo): RedirectResponse
    {
        $period = PeriodRoute::period($periodo);
        $user = self::user($request);

        return $this->attempt($period, 'calcular', function () use ($calculate, $period, $user): RedirectResponse {
            $settlement = $calculate->handle($period, $user->email, CarbonImmutable::now());
            $this->record('comisiones.settlement.calculated', $settlement, $user);

            return $this->done($settlement, 'calculada');
        });
    }

    public function approve(ApproveSettlementRequest $request, ApprovalGuard $guard, ApproveSettlement $approve, ApprovalMail $mail, string $periodo): RedirectResponse
    {
        $period = PeriodRoute::period($periodo);
        $user = self::user($request);

        if ($stale = $this->staleInputs($period, SettlementStatus::Draft, 'aprobar')) {
            return $stale;
        }

        try {
            $guard->assertMayApprove($period, $user);
            $settlement = $approve->handle($period, $user->email, CarbonImmutable::now(), $request->reviewedRevision(), $request->justifications());
        } catch (ApprovalRefused $refused) {
            return $this->back($period)->with('error', $refused->getMessage())->with('razones', $refused->reasons());
        } catch (UnresolvedAlerts $unresolved) {
            $rows = $request->rowsByKey();
            $errors = [];

            foreach ($unresolved->keys() as $key) {
                if (isset($rows[$key])) {
                    $errors["justificaciones.{$rows[$key]}.texto"] = __('comisiones::liquidacion.errores.justificacion_requerida');
                }
            }

            return $this->back($period)
                ->withInput()
                ->withErrors($errors)
                ->with('error', SettlementErrors::message($unresolved, $period, 'aprobar'));
        } catch (Throwable $exception) {
            return $this->refused($exception, $period, 'aprobar', withInput: true);
        }

        $this->record('comisiones.settlement.approved', $settlement, $user, ['justificaciones' => $settlement->justifications]);
        $done = $this->done($settlement, 'aprobada');

        return $mail->send($settlement, $user) ? $done : $done->with('error', self::mailWarning());
    }

    public function close(Request $request, CloseSettlement $close, string $periodo): RedirectResponse
    {
        $period = PeriodRoute::period($periodo);
        $user = self::user($request);

        if ($stale = $this->staleInputs($period, SettlementStatus::Approved, 'cerrar')) {
            return $stale;
        }

        return $this->attempt($period, 'cerrar', function () use ($close, $period, $user): RedirectResponse {
            $settlement = $close->handle($period, $user->email, CarbonImmutable::now());
            $this->record('comisiones.settlement.closed', $settlement, $user);

            return $this->done($settlement, 'cerrada');
        });
    }

    public function complement(ComplementarySettlementRequest $request, RegisterComplementarySettlement $complement, string $periodo): RedirectResponse
    {
        $period = PeriodRoute::period($periodo);
        $user = self::user($request);
        $reason = $request->reason();

        return $this->attempt($period, 'complementaria', function () use ($complement, $period, $user, $reason): RedirectResponse {
            $settlement = $complement->handle($period, $user->email, $reason, CarbonImmutable::now());
            $this->record('comisiones.settlement.complemented', $settlement, $user, ['version_anterior' => $settlement->previousVersion], $reason);

            return $this->done($settlement, 'complementaria');
        }, withInput: true);
    }

    /**
     * Refuses to approve or close the latest version when the budget, an
     * approved capture or a parameter version of the period changed after
     * it was calculated: it would sign off figures that leave them out.
     * Only checked when the latest version is in the status the step needs;
     * otherwise the step itself answers.
     *
     * @param  string  $step  aprobar or cerrar.
     */
    private function staleInputs(Period $period, SettlementStatus $status, string $step): ?RedirectResponse
    {
        $latest = SettlementRecord::query()->where('periodo', $period->key())->orderByDesc('version')
            ->first(['version', 'revision', 'estado', 'calculado_en']);

        if ($latest === null || $latest->estado !== $status->value || ! $this->changes->since($period, $latest->calculado_en)) {
            return null;
        }

        return $this->back($period)->with('error', __('comisiones::liquidacion.errores.entradas_cambiaron', [
            'periodo' => $period->key(),
            'revision' => $latest->revision,
            'paso' => __("comisiones::liquidacion.errores.pasos.{$step}"),
        ]));
    }

    /**
     * Runs one step and turns a refusal into a message on the period screen.
     *
     * @param  Closure(): RedirectResponse  $step
     */
    private function attempt(Period $period, string $action, Closure $step, bool $withInput = false): RedirectResponse
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            return $this->refused($exception, $period, $action, $withInput);
        }
    }

    /**
     * @throws Throwable when the exception is not one the flow throws on purpose.
     */
    private function refused(Throwable $exception, Period $period, string $action, bool $withInput = false): RedirectResponse
    {
        if (! SettlementErrors::handles($exception)) {
            throw $exception;
        }

        // A corrupt stored result needs IT. An InvalidArgumentException is either a
        // user input the engine refused or a bug (a malformed amount or date), and
        // they look the same here, so it is logged too. The other refusals are
        // expected answers of the flow.
        if ($exception instanceof CorruptSettlement || $exception instanceof InvalidArgumentException) {
            report($exception);
        }

        $redirect = $this->back($period)->with('error', SettlementErrors::message($exception, $period, $action));

        return $withInput ? $redirect->withInput() : $redirect;
    }

    private function done(Settlement $settlement, string $message): RedirectResponse
    {
        return $this->back($settlement->period)->with('status', __("comisiones::liquidacion.mensajes.{$message}", [
            'periodo' => $settlement->period->key(),
            'version' => $settlement->version,
            'revision' => $settlement->revision,
        ]));
    }

    private function back(Period $period): RedirectResponse
    {
        return redirect()->route('comisiones.periodos.show', $period->key());
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function record(string $event, Settlement $settlement, User $user, array $changes = [], ?string $reason = null): void
    {
        $this->audit->record(
            event: $event,
            subjectType: 'liquidacion',
            subjectId: $this->settlements->record($settlement->period, $settlement->version)?->id,
            changes: ['estado' => $settlement->status->value, ...$changes],
            context: [
                'version' => $settlement->version,
                'revision' => $settlement->revision,
                'alertas_bloqueantes' => count($settlement->result->blockingAlerts()),
            ],
            period: $settlement->period->key(),
            user: $user,
            reason: $reason,
        );
    }

    /**
     * Shown next to the approval when ApprovalMail could not send the
     * consolidated report: the approval stands, the approver passes it on.
     */
    private static function mailWarning(): string
    {
        $recipients = ApprovalMail::recipients();

        return $recipients === []
            ? __('comisiones::liquidacion.mensajes.correo_sin_destinatarios')
            : __('comisiones::liquidacion.mensajes.correo_no_enviado', ['destinatarios' => Arr::join($recipients, ', ', ' y ')]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
