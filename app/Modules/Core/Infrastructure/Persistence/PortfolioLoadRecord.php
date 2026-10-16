<?php

namespace App\Modules\Core\Infrastructure\Persistence;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Database\Factories\PortfolioLoadRecordFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A row of "portfolio_loads" (CargaCartera): one receivables snapshot of a
 * company on its cutoff date. Snapshots are never overwritten: each keeps
 * its own invoices. Amounts are exact decimal strings.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property CarbonImmutable $cutoff_date
 * @property int|null $load_id The Ingesta load (LoadRecord::$id) it came from.
 * @property LoadSource $source
 * @property int $invoice_count
 * @property string $total Sum of the invoice balances.
 * @property string $overdue Sum of the balances of the invoices past due on the cutoff date.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, InvoiceRecord> $invoices
 */
#[Table(name: 'portfolio_loads')]
#[Fillable(['company', 'cutoff_date', 'load_id', 'source', 'invoice_count', 'total', 'overdue'])]
#[UseFactory(PortfolioLoadRecordFactory::class)]
final class PortfolioLoadRecord extends Model
{
    /** @use HasFactory<PortfolioLoadRecordFactory> */
    use HasFactory;

    /**
     * @return HasMany<InvoiceRecord, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(InvoiceRecord::class, 'portfolio_load_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'cutoff_date' => 'immutable_date',
            'load_id' => 'integer',
            'source' => LoadSource::class,
            'invoice_count' => 'integer',
            'total' => 'decimal:2',
            'overdue' => 'decimal:2',
        ];
    }
}
