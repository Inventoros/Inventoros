<?php

declare(strict_types=1);

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/** Product text must retain carriage returns instead of changing identifiers. */
abstract class ProductSpreadsheetValueBinder extends DefaultValueBinder
{
    protected bool $preserveCr = true;

    public function bindValue(Cell $cell, mixed $value): bool
    {
        // Laravel Excel registers its binder globally; PhpSpreadsheet reads
        // preserveCr from the workbook instance when storing string values.
        $cell->getWorksheet()->getParent()?->setValueBinder($this);

        return parent::bindValue($cell, $value);
    }
}
