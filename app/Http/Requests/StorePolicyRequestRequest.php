<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePolicyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id'          => ['required', 'string', 'max:100'],
            'customer_name'     => ['required', 'string', 'max:200'],
            'date_of_birth'     => ['required', 'date_format:Y-m-d'],
            'destination'       => ['required', 'in:DOMESTIC,INTERNATIONAL'],
            'trip_start'        => ['required', 'date_format:Y-m-d'],
            'trip_end'          => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code'         => ['required', 'in:BASIC,PLUS,PREMIUM'],
            'sum_insured'       => ['required', 'integer', 'in:100000,300000,500000'],
        ];
    }

    public function messages(): array
    {
        return [
            'destination.in'   => 'Destination must be DOMESTIC or INTERNATIONAL.',
            'plan_code.in'     => 'Plan code must be BASIC, PLUS, or PREMIUM.',
            'sum_insured.in'   => 'Sum insured must be 100000, 300000, or 500000.',
            'trip_end.after_or_equal' => 'Trip end date must not be earlier than trip start date.',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
