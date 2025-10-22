<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceCheckInRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, Rule|string>>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'uuid', Rule::exists('stores', 'id')],
            'shift_store_id' => ['required', 'uuid', Rule::exists('shift_stores', 'id')],
            'image_in' => ['required', 'string'],
            'check_in' => ['nullable', 'date'],
            'latitude_in' => ['required', 'numeric', 'between:-90,90'],
            'longitude_in' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
