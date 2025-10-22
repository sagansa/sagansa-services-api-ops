<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceCheckOutRequest extends FormRequest
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
            'image_out' => ['required', 'string'],
            'check_out' => ['nullable', 'date'],
            'latitude_out' => ['required', 'numeric', 'between:-90,90'],
            'longitude_out' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
