<?php

namespace App\Modules\Core\Infrastructure\Persistence;

use App\Modules\Core\Database\Factories\ContactRecordFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "contacts": a person of a client who receives the collection
 * e-mails and calls. Removing the client for good removes its contacts.
 *
 * @property int $id
 * @property int $client_id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $position
 * @property bool $is_primary
 * @property string $source Where it came from: sap, or a user of the platform.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ClientRecord $client
 */
#[Table(name: 'contacts')]
#[Fillable(['client_id', 'name', 'email', 'phone', 'position', 'is_primary', 'source'])]
#[UseFactory(ContactRecordFactory::class)]
final class ContactRecord extends Model
{
    /** @use HasFactory<ContactRecordFactory> */
    use HasFactory;

    /**
     * The column defaults, so a new contact reads them before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_primary' => false,
        'source' => 'sap',
    ];

    /**
     * The client, also when it is soft-deleted.
     *
     * @return BelongsTo<ClientRecord, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientRecord::class, 'client_id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'is_primary' => 'boolean',
        ];
    }
}
