<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One serial-tracked unit moved by an inter-company transfer line: the
 * source organization's serial (left with status `transferred`) and the
 * destination organization's serial created or revived for it. Plain ids:
 * either serial may be deleted later without touching this record.
 *
 * @property int $id
 * @property int $inter_company_transfer_line_id
 * @property string $serial_number
 * @property int $from_serial_id
 * @property int $to_serial_id
 * @property-read InterCompanyTransferLine $line
 */
class InterCompanyTransferSerial extends Model
{
    protected $fillable = [
        'inter_company_transfer_line_id',
        'serial_number',
        'from_serial_id',
        'to_serial_id',
    ];

    protected $casts = [
        'from_serial_id' => 'integer',
        'to_serial_id' => 'integer',
    ];

    /**
     * @return BelongsTo<InterCompanyTransferLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(InterCompanyTransferLine::class, 'inter_company_transfer_line_id');
    }
}
