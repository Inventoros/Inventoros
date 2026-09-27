<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Documents\DocumentRecipients;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional overrides when emailing a document (purchase order to a supplier,
 * invoice to a customer): a different recipient, CC addresses and a short
 * message. Everything is optional; with no input the document goes to the
 * address on file. Access is enforced by the route's permission middleware.
 *
 * `cc` accepts a comma separated string (web form) or an array (API).
 */
class SendDocumentEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to' => ['nullable', 'string', 'email', 'max:255'],
            'cc' => ['nullable', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) && ! is_array($value)) {
                    $fail('The CC field must be a list of email addresses.');

                    return;
                }

                if (is_string($value) && strlen($value) > 1000) {
                    $fail('The CC field is too long.');

                    return;
                }

                $addresses = DocumentRecipients::split($value);

                if (count($addresses) > DocumentRecipients::MAX_CC) {
                    $fail('You can CC at most '.DocumentRecipients::MAX_CC.' addresses.');

                    return;
                }

                foreach ($addresses as $address) {
                    if (strlen($address) > 255 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                        $fail("{$address} is not a valid email address.");

                        return;
                    }
                }
            }],
            'message' => ['nullable', 'string', 'max:'.DocumentRecipients::MAX_MESSAGE_LENGTH],
        ];
    }

    public function recipient(): ?string
    {
        $to = $this->validated('to');

        return is_string($to) && trim($to) !== '' ? trim($to) : null;
    }

    /**
     * @return array<int, string>
     */
    public function ccList(): array
    {
        return DocumentRecipients::split($this->validated('cc'));
    }

    public function customMessage(): ?string
    {
        return DocumentRecipients::message($this->validated('message'));
    }
}
