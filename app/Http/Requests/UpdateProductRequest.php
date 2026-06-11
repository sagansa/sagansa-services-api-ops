<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorised to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product instanceof Product ? $product->id : $product;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'string', Rule::in(['single', 'bundle'])],
            'bundle_pricing_mode' => ['sometimes', 'string', Rule::in(['fixed', 'sum_components'])],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sku' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->ignore($productId),
            ],
            'barcode' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
                Rule::unique('products', 'barcode')->ignore($productId),
            ],
            'request' => ['sometimes', 'nullable', 'boolean'],
            'remaining' => ['sometimes', 'nullable', 'boolean'],
            'unit_id' => ['sometimes', 'nullable', 'uuid', 'exists:units,id'],
            'category_id' => ['sometimes', 'nullable', 'uuid', 'exists:categories,id'],
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['sometimes', 'nullable'],
            'variants' => ['sometimes', 'array'],
            'variants.*.id' => ['sometimes', 'uuid'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:150'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variant_groups' => ['sometimes', 'array'],
            'variant_groups.*.id' => ['nullable', 'uuid'],
            'variant_groups.*.name' => ['required_with:variant_groups', 'string', 'max:255'],
            'variant_groups.*.is_required' => ['nullable', 'boolean'],
            'variant_groups.*.variants' => ['sometimes', 'array'],
            'variant_groups.*.variants.*.name' => ['required', 'string', 'max:255'],
            'variant_groups.*.variants.*.sku' => ['nullable', 'string', 'max:150'],
            'variant_groups.*.variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variant_groups.*.variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variant_groups.*.variants.*.is_active' => ['nullable', 'boolean'],
            'variant_groups.*.variants.*.available_with_variants' => ['nullable', 'array'],
            'variant_groups.*.variants.*.available_with_variants.*' => ['uuid'],
            'modifications' => ['sometimes', 'array'],
            'modifications.*.id' => ['sometimes', 'uuid'],
            'modifications.*.name' => ['required_with:modifications', 'string', 'max:255'],
            'modifications.*.price' => ['nullable', 'numeric', 'min:0'],
            'modifications.*.is_active' => ['nullable', 'boolean'],
            'bundle_items' => ['sometimes', 'array'],
            'bundle_items.*.component_product_id' => ['required_with:bundle_items', 'uuid', 'exists:products,id'],
            'bundle_items.*.quantity' => ['required_with:bundle_items', 'integer', 'min:1'],
            'stores' => ['sometimes', 'array'],
            'stores.*.id' => ['required_with:stores', 'uuid', 'exists:stores,id'],
            'stores.*.price' => ['nullable', 'numeric', 'min:0'],
            'store_ids' => ['sometimes', 'array'],
            'store_ids.*' => ['uuid', 'exists:stores,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'request' => $this->boolean('request'),
            'remaining' => $this->boolean('remaining'),
            'variants' => $this->decodeJsonArray('variants'),
            'variant_groups' => $this->decodeJsonArray('variant_groups'),
            'modifications' => $this->decodeJsonArray('modifications'),
            'bundle_items' => $this->decodeJsonArray('bundle_items'),
            'stores' => $this->decodeJsonArray('stores'),
        ]);
    }

    private function decodeJsonArray(string $key): ?array
    {
        $value = $this->input($key);

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return is_array($value) ? $value : null;
    }
}
