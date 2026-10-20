<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use App\Models\User;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registers a manual capture (§7.4): its support is stored and the capture
 * is created pending, audited as comisiones.capture.created with the
 * capture kind as subject type. It enters a calculation only once another
 * user approves it (EloquentSettlementInputs reads approved ones only).
 *
 * The screens check the period lock and the permission before calling.
 */
final class ManualCaptures
{
    public function __construct(
        private readonly Supports $supports,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Stores the support and creates the capture, pending.
     *
     * @param  array<string, mixed>  $attributes  The columns of the capture.
     */
    public function create(
        CaptureKind $kind,
        array $attributes,
        UploadedFile $support,
        User $user,
        string $period,
    ): ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord {
        $path = $this->supports->store($support, $kind);

        try {
            return DB::transaction(function () use ($kind, $attributes, $path, $user, $period, $support) {
                $columns = [
                    ...$attributes,
                    'soporte' => $path,
                    'estado' => CaptureStatus::Pending->value,
                    'created_by' => $user->id,
                ];

                $capture = match ($kind) {
                    CaptureKind::Obr => ObrRecord::query()->create($columns),
                    CaptureKind::Adjustment => AdjustmentRecord::query()->create($columns),
                    CaptureKind::Crossing => CrossingRecord::query()->create([
                        ...$columns,
                        'clave' => 'manual-'.Str::uuid()->toString(),
                        'origen' => CrossingRecord::MANUAL,
                        'estado_cruce' => 'vigente',
                    ]),
                    CaptureKind::Reassignment => ReassignmentRecord::query()->create($columns),
                };

                $this->audit->record(
                    event: 'comisiones.capture.created',
                    subjectType: $kind->value,
                    subjectId: $capture->id,
                    changes: $attributes,
                    context: ['soporte' => $support->getClientOriginalName()],
                    period: $period,
                    user: $user,
                );

                return $capture;
            });
        } catch (Throwable $exception) {
            $this->supports->delete($path);

            throw $exception;
        }
    }
}
