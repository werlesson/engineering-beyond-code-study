<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Valida a criação de um booking com todas as referências escopadas à empresa do usuário.
 *
 * Um ID de outra empresa falha na mesma regra exists escopada que um ID inexistente,
 * produzindo a mesma mensagem e não revelando a existência do registro.
 */
class StoreBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string|Exists>>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'professional_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('professionals', 'id')->where('company_id', $companyId),
            ],
            'customer_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId),
            ],
            'service_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('services', 'id')->where('company_id', $companyId),
                Rule::exists('professional_service', 'service_id')
                    ->where('company_id', $companyId)
                    ->where('professional_id', (int) $this->input('professional_id')),
            ],
            'starts_at' => [
                'bail',
                'required',
                'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z',
                'after:now',
            ],
        ];
    }
}
