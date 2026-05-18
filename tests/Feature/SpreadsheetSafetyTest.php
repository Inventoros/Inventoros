<?php

namespace Tests\Feature;

use App\Support\SpreadsheetSafety;
use Tests\TestCase;

class SpreadsheetSafetyTest extends TestCase
{
    public function test_neutralise_prefixes_formula_trigger_characters(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", SpreadsheetSafety::neutralise('=HYPERLINK("x")'));
        $this->assertSame("'+1+1", SpreadsheetSafety::neutralise('+1+1'));
        $this->assertSame("'-cmd|'/c calc'!A1", SpreadsheetSafety::neutralise("-cmd|'/c calc'!A1"));
        $this->assertSame("'@SUM(A1:A2)", SpreadsheetSafety::neutralise('@SUM(A1:A2)'));
        $this->assertSame("'\tphony", SpreadsheetSafety::neutralise("\tphony"));
        $this->assertSame("'\rcr", SpreadsheetSafety::neutralise("\rcr"));
    }

    public function test_neutralise_leaves_safe_values_unchanged(): void
    {
        $this->assertSame('Widget A', SpreadsheetSafety::neutralise('Widget A'));
        $this->assertSame('ORD-1234', SpreadsheetSafety::neutralise('ORD-1234'));
        $this->assertSame('', SpreadsheetSafety::neutralise(''));
        $this->assertSame(null, SpreadsheetSafety::neutralise(null));
        $this->assertSame(42, SpreadsheetSafety::neutralise(42));
        $this->assertSame(9.99, SpreadsheetSafety::neutralise(9.99));
    }

    public function test_neutralise_row_handles_mixed_cells(): void
    {
        $row = ['safe', '=evil', 42, null, '-also-evil'];
        $this->assertSame(['safe', "'=evil", 42, null, "'-also-evil"], SpreadsheetSafety::neutraliseRow($row));
    }

    public function test_sanitise_import_strips_leading_formula_characters(): void
    {
        $this->assertSame('HYPERLINK("x")', SpreadsheetSafety::sanitiseImport('=HYPERLINK("x")'));
        $this->assertSame('cmd', SpreadsheetSafety::sanitiseImport('-cmd'));
        $this->assertSame('benign', SpreadsheetSafety::sanitiseImport('benign'));
        // Multiple risky leading chars stacked.
        $this->assertSame('after', SpreadsheetSafety::sanitiseImport("=+-@after"));
    }

    public function test_sanitise_import_leaves_non_strings_untouched(): void
    {
        $this->assertSame(42, SpreadsheetSafety::sanitiseImport(42));
        $this->assertSame(null, SpreadsheetSafety::sanitiseImport(null));
        $this->assertSame('', SpreadsheetSafety::sanitiseImport(''));
    }
}
