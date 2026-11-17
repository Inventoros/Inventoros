<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use InvalidArgumentException;

/**
 * One setting a provider needs, rendered on Settings > Exchange rates.
 *
 * type is "text", "url" or "secret". A secret (an API key) is encrypted at
 * rest, never sent to the browser, and kept when the field is left blank on
 * save.
 *
 * @api
 */
final class ProviderConfigField
{
    public const TYPES = ['text', 'url', 'secret'];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $required = false,
        public readonly string $help = '',
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}\z/', $key) !== 1) {
            throw new InvalidArgumentException("Provider setting \"{$key}\" must be a snake_case key.");
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Provider setting \"{$key}\" has unknown type \"{$type}\".");
        }
    }

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * Validation rules for a submitted value.
     *
     * @return array<int, string>
     */
    public function rules(): array
    {
        return match ($this->type) {
            'url' => ['nullable', 'string', 'url:http,https', 'max:255'],
            default => ['nullable', 'string', 'max:500'],
        };
    }

    /**
     * @return array{key: string, label: string, type: string, required: bool, help: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'help' => $this->help,
        ];
    }
}
