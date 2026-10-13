<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Units of one lot moved by an inter-company transfer line: drawn from the
 * source organization's batch and added to the destination organization's
 * batch with the same lot number and dates. Plain ids: either batch may be
 * deleted later without touching this record.
 *
 * @property int $id
 * @property int $inter_company_transfer_line_id
 * @property string $batch_number
 * @property Carbon|null $manufactured_date
 * @property Carbon|null $expiry_date
 * @property int $quantity
 * @property int $from_batch_id
 * @property int $to_batch_id
 * @property-read InterCompanyTransferLine $line
 */
class InterCompanyTransferBatch extends Model
{
    protected $fillable = [
        'inter_company_transfer_line_id',
        'batch_number',
        'manufactured_date',
        'expiry_date',
        'quantity',
        'from_batch_id',
        'to_batch_id',
    ];

    protected $casts = [
        'manufactured_date' => 'date',
        'expiry_date' => 'date',
        'quantity' => 'integer',
        'from_batch_id' => 'integer',
        'to_batch_id' => 'integer',
    ];

    /**
     * @return BelongsTo<InterCompanyTransferLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(InterCompanyTransferLine::class, 'inter_company_transfer_line_id');
    }
}
