<?php

namespace App\Modules\Complaints\Http\Requests;

use App\Modules\Complaints\Enums\ComplaintContactMethod;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreComplaintRequest extends FormRequest
{
    public const HONEYPOT = 'website';

    public function authorize(): bool
    {
        return true;
    }

    public function honeypotFilled(): bool
    {
        $value = $this->input(self::HONEYPOT);

        return is_string($value) ? trim($value) !== '' : $value !== null && $value !== [];
    }

    public function validateResolved(): void
    {
        if (! $this->honeypotFilled()) {
            parent::validateResolved();
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $noControl = 'not_regex:/[\x00-\x1F\x7F]/';
        $method = $this->input('contactMethod');
        $phoneRequired = in_array($method, [ComplaintContactMethod::CALL->value, ComplaintContactMethod::WHATSAPP->value], true);

        return [
            'name' => ['required', 'string', 'min:2', 'max:100', $noControl],
            'topic' => ['required', Rule::in(['ORDER_PROBLEM', 'PRODUCT_QUESTION', 'OTHER'])],
            'orderNumber' => ['nullable', 'string', 'max:100', $noControl],
            'message' => ['required', 'string', 'max:5000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
            'contactMethod' => ['required', Rule::in(['CALL', 'WHATSAPP', 'EMAIL'])],
            'phone' => [$phoneRequired ? 'required' : 'nullable', 'string', 'max:20', $noControl, function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                try {
                    PhoneNumber::fromGhanaInput((string) $value);
                } catch (\InvalidArgumentException) {
                    $fail('The phone must be a valid Ghana phone number.');
                }
            }],
            'email' => [$method === ComplaintContactMethod::EMAIL->value ? 'required' : 'nullable', 'string', 'email:rfc', 'max:254', $noControl],
        ];
    }

    /** @return array{name:string,topic:string,orderNumber:?string,message:string,contactMethod:string,phone:?string,email:?string} */
    public function submission(): array
    {
        $phone = trim((string) $this->input('phone', ''));
        $email = trim((string) $this->input('email', ''));

        return [
            'name' => trim((string) $this->input('name')),
            'topic' => (string) $this->input('topic'),
            'orderNumber' => trim((string) $this->input('orderNumber', '')) ?: null,
            'message' => trim((string) $this->input('message')),
            'contactMethod' => (string) $this->input('contactMethod'),
            'phone' => $phone === '' ? null : PhoneNumber::fromGhanaInput($phone)->e164,
            'email' => $email === '' ? null : mb_strtolower($email),
        ];
    }
}
