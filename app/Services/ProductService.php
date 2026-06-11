<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductOption;
use App\Models\Inventory\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Persistence + media handling for products.
 *
 * Extracted from the Inventory\ProductController store/update god-methods
 * (P2-8): currency-map normalisation, base64 image validation/upload, and the
 * option/variant sync transaction all live here. The controller keeps request
 * validation, the plugin hooks, and the response; it hands a validated array to
 * create()/update() and gets back the persisted Product.
 */
final class ProductService
{
    /**
     * Create a product (plus its options/variants) from validated data.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        $data = $this->normaliseCurrencies($data);
        $data = $this->processStoreImages($data);

        $options = $data['options'] ?? [];
        $variants = $data['variants'] ?? [];
        unset($data['options'], $data['variants']);

        return DB::transaction(function () use ($data, $options, $variants) {
            $product = Product::create($data);

            if ($product->has_variants && ! empty($options)) {
                foreach ($options as $index => $optionData) {
                    ProductOption::create([
                        'product_id' => $product->id,
                        'name' => $optionData['name'],
                        'values' => $optionData['values'],
                        'position' => $index,
                    ]);
                }

                foreach ($variants as $index => $variantData) {
                    ProductVariant::create($this->variantPayload($product, $variantData, $index));
                }
            }

            return $product;
        });
    }

    /**
     * Update a product (plus its options/variants) from validated data.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        $data = $this->normaliseCurrencies($data);

        if (isset($data['images'])) {
            $data = $this->processUpdateImages($product, $data);
        }

        $options = $data['options'] ?? [];
        $variants = $data['variants'] ?? [];
        unset($data['options'], $data['variants']);

        DB::transaction(function () use ($product, $data, $options, $variants) {
            $product->update($data);

            if ($data['has_variants'] ?? $product->has_variants) {
                $this->syncOptions($product, $options);
                $this->syncVariants($product, $variants);
            } else {
                // If variants are disabled, delete all options and variants
                $product->options()->delete();
                $product->variants()->delete();
            }
        });

        return $product;
    }

    /**
     * Convert the price_in_currencies list-of-pairs into a {currency: price} map.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normaliseCurrencies(array $data): array
    {
        if (! empty($data['price_in_currencies'])) {
            $currencies = [];
            foreach ($data['price_in_currencies'] as $currencyPrice) {
                $currencies[$currencyPrice['currency']] = $currencyPrice['price'];
            }
            $data['price_in_currencies'] = $currencies;
        }

        return $data;
    }

    /**
     * Validate + store base64 images on create, setting the thumbnail.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function processStoreImages(array $data): array
    {
        if (empty($data['images'])) {
            return $data;
        }

        $imagePaths = [];
        foreach ($data['images'] as $index => $imageData) {
            if (isset($imageData['preview']) && str_starts_with($imageData['preview'], 'data:image/')) {
                $this->validateBase64Image($imageData['preview']);

                $base64 = substr($imageData['preview'], strpos($imageData['preview'], ',') + 1);
                $imageContent = base64_decode($base64);

                $extension = $this->getImageExtensionFromBase64($imageData['preview']);
                $filename = 'products/'.uniqid().'_'.time().'.'.$extension;

                Storage::disk('public')->put($filename, $imageContent);
                $imagePaths[] = $filename;

                if ($index === 0) {
                    $data['thumbnail'] = $filename;
                }
            }
        }
        $data['images'] = $imagePaths;

        return $data;
    }

    /**
     * Reconcile images on update: keep existing URLs, upload new base64,
     * delete removed files, set the thumbnail.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function processUpdateImages(Product $product, array $data): array
    {
        $imagePaths = [];
        $oldImages = $product->images ?? [];

        foreach ($data['images'] as $index => $imageData) {
            // Existing image kept by URL
            if (isset($imageData['url']) && ! str_starts_with($imageData['preview'], 'data:image/')) {
                $path = str_replace('/storage/', '', $imageData['url']);
                $imagePaths[] = $path;
            }
            // New base64 image uploaded
            elseif (isset($imageData['preview']) && str_starts_with($imageData['preview'], 'data:image/')) {
                $this->validateBase64Image($imageData['preview']);

                $base64 = substr($imageData['preview'], strpos($imageData['preview'], ',') + 1);
                $imageContent = base64_decode($base64);

                $extension = $this->getImageExtensionFromBase64($imageData['preview']);
                $filename = 'products/'.uniqid().'_'.time().'.'.$extension;

                Storage::disk('public')->put($filename, $imageContent);
                $imagePaths[] = $filename;
            }

            if ($index === 0 && ! empty($imagePaths)) {
                $data['thumbnail'] = end($imagePaths);
            }
        }

        // Delete removed images
        foreach ($oldImages as $oldImage) {
            if (! in_array($oldImage, $imagePaths)) {
                Storage::disk('public')->delete($oldImage);
            }
        }

        $data['images'] = $imagePaths;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $variantData
     * @return array<string, mixed>
     */
    private function variantPayload(Product $product, array $variantData, int $index): array
    {
        return [
            'product_id' => $product->id,
            'organization_id' => $product->organization_id,
            'sku' => $variantData['sku'] ?? null,
            'barcode' => $variantData['barcode'] ?? null,
            'title' => $variantData['title'] ?? implode(' / ', array_values($variantData['option_values'])),
            'option_values' => $variantData['option_values'],
            'price' => $variantData['price'] ?? null,
            'purchase_price' => $variantData['purchase_price'] ?? null,
            'stock' => $variantData['stock'] ?? 0,
            'min_stock' => $variantData['min_stock'] ?? 0,
            'is_active' => $variantData['is_active'] ?? true,
            'position' => $index,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    private function syncOptions(Product $product, array $options): void
    {
        $existingOptionIds = $product->options()->pluck('id')->toArray();
        $incomingOptionIds = [];

        foreach ($options as $index => $optionData) {
            if (! empty($optionData['id'])) {
                $option = ProductOption::find($optionData['id']);
                if ($option && $option->product_id === $product->id) {
                    $option->update([
                        'name' => $optionData['name'],
                        'values' => $optionData['values'],
                        'position' => $index,
                    ]);
                    $incomingOptionIds[] = $optionData['id'];
                }
            } else {
                $option = ProductOption::create([
                    'product_id' => $product->id,
                    'name' => $optionData['name'],
                    'values' => $optionData['values'],
                    'position' => $index,
                ]);
                $incomingOptionIds[] = $option->id;
            }
        }

        $optionsToDelete = array_diff($existingOptionIds, $incomingOptionIds);
        if (! empty($optionsToDelete)) {
            ProductOption::whereIn('id', $optionsToDelete)->delete();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        $existingVariantIds = $product->variants()->pluck('id')->toArray();
        $incomingVariantIds = [];

        foreach ($variants as $index => $variantData) {
            $variantPayload = $this->variantPayload($product, $variantData, $index);

            if (! empty($variantData['id'])) {
                $variant = ProductVariant::find($variantData['id']);
                if ($variant && $variant->product_id === $product->id) {
                    $variant->update($variantPayload);
                    $incomingVariantIds[] = $variantData['id'];
                }
            } else {
                $variant = ProductVariant::create($variantPayload);
                $incomingVariantIds[] = $variant->id;
            }
        }

        $variantsToDelete = array_diff($existingVariantIds, $incomingVariantIds);
        if (! empty($variantsToDelete)) {
            ProductVariant::whereIn('id', $variantsToDelete)->delete();
        }
    }

    /**
     * Map a base64 data-URL's mime type to a file extension.
     */
    private function getImageExtensionFromBase64(string $base64): string
    {
        $mimeType = substr($base64, 5, strpos($base64, ';') - 5);

        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /**
     * Validate base64 image data: format, size (5 MB), real image content,
     * mime type, and dimensions (4096²). Throws on any failure.
     *
     * @throws InvalidArgumentException
     */
    private function validateBase64Image(string $base64Data): bool
    {
        if (! preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/', $base64Data)) {
            throw new InvalidArgumentException('Invalid image format. Only JPEG, PNG, GIF, and WebP are allowed.');
        }

        $base64Content = substr($base64Data, strpos($base64Data, ',') + 1);
        $imageContent = base64_decode($base64Content, true);

        if ($imageContent === false) {
            throw new InvalidArgumentException('Invalid base64 encoding.');
        }

        $maxSize = 5 * 1024 * 1024;
        if (strlen($imageContent) > $maxSize) {
            throw new InvalidArgumentException('Image size must be less than 5MB.');
        }

        $imageInfo = @getimagesizefromstring($imageContent);
        if ($imageInfo === false) {
            throw new InvalidArgumentException('Invalid image data. File does not appear to be a valid image.');
        }

        $allowedMimes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if (! in_array($imageInfo[2], $allowedMimes)) {
            throw new InvalidArgumentException('Invalid image type. Only JPEG, PNG, GIF, and WebP are allowed.');
        }

        $maxDimension = 4096;
        if ($imageInfo[0] > $maxDimension || $imageInfo[1] > $maxDimension) {
            throw new InvalidArgumentException("Image dimensions must not exceed {$maxDimension}x{$maxDimension} pixels.");
        }

        return true;
    }
}
