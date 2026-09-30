<?php

declare(strict_types=1);

namespace Tests\Feature;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Invoice, purchase order and report PDFs are rendered with dompdf using the
 * package defaults: remote fetching off and the chroot pinned to the app. These
 * checks pin that behaviour across dompdf upgrades: images inside the app (and
 * inline data URIs) still render, while files outside the chroot and remote
 * URLs are never read.
 */
final class PdfImageSandboxTest extends TestCase
{
    private ?string $outside = null;

    protected function tearDown(): void
    {
        if ($this->outside !== null) {
            File::delete($this->outside);
        }

        parent::tearDown();
    }

    public function test_the_pdf_renderer_keeps_remote_access_off_and_the_chroot_on_the_app(): void
    {
        $options = Pdf::loadHTML('<p>x</p>')->getDomPDF()->getOptions();

        $this->assertFalse($options->getIsRemoteEnabled());
        $this->assertFalse($options->getIsPhpEnabled());
        $this->assertSame([realpath(base_path())], $options->getChroot());
    }

    public function test_an_image_inside_the_app_is_embedded(): void
    {
        $png = public_path('apple-touch-icon.png');
        $this->assertFileExists($png);

        $pdf = $this->render('<img src="'.$png.'" width="40" height="40">');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function test_an_inline_data_uri_image_is_embedded(): void
    {
        $data = base64_encode((string) file_get_contents(public_path('apple-touch-icon.png')));

        $pdf = $this->render('<img src="data:image/png;base64,'.$data.'" width="40" height="40">');

        $this->assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function test_an_image_outside_the_chroot_is_not_read(): void
    {
        $this->outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inventoros-outside-'.bin2hex(random_bytes(4)).'.png';
        File::copy(public_path('apple-touch-icon.png'), $this->outside);

        $pdf = $this->render('<img src="'.$this->outside.'" width="40" height="40">');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function test_a_remote_image_is_not_fetched(): void
    {
        $pdf = $this->render('<img src="https://example.invalid/logo.png" width="40" height="40">');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    private function render(string $body): string
    {
        return Pdf::loadHTML('<html><body>'.$body.'<p>Invoice</p></body></html>')->output();
    }
}
