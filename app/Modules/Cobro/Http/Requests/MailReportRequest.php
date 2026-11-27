<?php

namespace App\Modules\Cobro\Http\Requests;

use App\Modules\Cobro\Application\Reports\MailReportFilter;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Reports\ReportClients;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /cobro/informe: the report filters. The company must be one of the
 * user's scope, the advisor one CarteraRepository::advisors() shows the
 * user, as "<company>:<code>", and the group one of the SAP groups of the
 * clients in scope (clients()). Without dates the report covers the last
 * DEFAULT_DAYS days ending today (Bogotá); with only «desde», it ends today,
 * or on that same day when «desde» is later. The route already checked
 * cobro.view (and cobro.export for the download).
 */
final class MailReportRequest extends FormRequest
{
    public const int DEFAULT_DAYS = 30;

    public const int MAX_CLIENT = 100;

    /** @var array<string, string>|null */
    private ?array $advisorOptions = null;

    private ?ReportClients $clients = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'empresa' => ['nullable', 'string', Rule::in(array_map(fn (CompanyCode $code): string => $code->value, $this->scope()))],
            'cliente' => ['nullable', 'string', 'max:'.self::MAX_CLIENT],
            'asesor' => ['nullable', 'string', Rule::in(array_keys($this->advisorOptions()))],
            'grupo' => ['nullable', 'string', Rule::in($this->clients()->groups())],
            'tipo' => ['nullable', 'string', Rule::enum(RunKind::class)],
            'estado' => ['nullable', 'string', Rule::enum(ItemStatus::class)],
            'respondio' => ['nullable', 'string', Rule::in(['si', 'no'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('cobro::informe.filtros');
    }

    public function filter(): MailReportFilter
    {
        $from = $this->day('desde');
        $today = CarbonImmutable::today(CobroServiceProvider::TIMEZONE);
        $to = $this->day('hasta') ?? ($from !== null && $from->greaterThan($today) ? $from : $today);
        $company = CompanyCode::tryFrom($this->string('empresa')->toString());
        $client = trim($this->string('cliente')->toString());
        $replied = $this->string('respondio')->toString();
        $group = $this->string('grupo')->toString();
        [$advisorCompany, $advisorCode] = $this->advisor();
        $clientIds = null;

        if ($advisorCompany !== null && $advisorCode !== null) {
            $clientIds = $this->clients()->ofAdvisor(MailReportFilter::advisorKey($advisorCompany, $advisorCode));
        }

        if ($group !== '') {
            $ofGroup = $this->clients()->ofGroup($group);
            $clientIds = $clientIds === null ? $ofGroup : array_values(array_intersect($clientIds, $ofGroup));
        }

        return new MailReportFilter(
            from: $from ?? $to->subDays(self::DEFAULT_DAYS - 1),
            to: $to,
            companies: $company === null ? $this->scope() : [$company],
            client: $client === '' ? null : $client,
            advisorCompany: $advisorCompany,
            advisorCode: $advisorCode,
            kind: RunKind::tryFrom($this->string('tipo')->toString()),
            status: ItemStatus::tryFrom($this->string('estado')->toString()),
            replied: $replied === '' ? null : $replied === 'si',
            group: $group === '' ? null : $group,
            clientIds: $clientIds,
        );
    }

    /**
     * The advisor and SAP group of every client in scope, read from Core
     * once per request (ReportClients).
     */
    public function clients(): ReportClients
    {
        return $this->clients ??= ReportClients::read($this->container->make(CarteraRepository::class), $this->scope());
    }

    /**
     * The advisors the user may filter by: "<company>:<code>" => name, with
     * the company in the group view.
     *
     * @return array<string, string>
     */
    public function advisorOptions(): array
    {
        if ($this->advisorOptions === null) {
            $group = $this->container->make(CurrentCompany::class)->isGroup();
            $this->advisorOptions = [];

            foreach ($this->container->make(CarteraRepository::class)->advisors() as $advisor) {
                $this->advisorOptions[MailReportFilter::advisorKey($advisor->company, $advisor->code)] = $group
                    ? "{$advisor->name} ({$advisor->company->value})"
                    : $advisor->name;
            }
        }

        return $this->advisorOptions;
    }

    /**
     * @return list<CompanyCode>
     */
    public function scope(): array
    {
        return array_map(fn (Company $company): CompanyCode => $company->code, $this->container->make(CurrentCompany::class)->scope());
    }

    /**
     * @return array{CompanyCode|null, int|null}
     */
    private function advisor(): array
    {
        $parts = explode(':', $this->string('asesor')->toString(), 2);

        return count($parts) === 2 && ctype_digit($parts[1])
            ? [CompanyCode::tryFrom($parts[0]), (int) $parts[1]]
            : [null, null];
    }

    private function day(string $key): ?CarbonImmutable
    {
        $value = $this->string($key)->toString();

        return $value === '' ? null : (CarbonImmutable::createFromFormat('!Y-m-d', $value, CobroServiceProvider::TIMEZONE) ?: null);
    }
}
