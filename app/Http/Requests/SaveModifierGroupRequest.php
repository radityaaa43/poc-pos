<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** REQ-008 AC-01 and AC-02. The route carries the permission. */
class SaveModifierGroupRequest extends FormRequest
{
    public function rules(): array
    {
        $groupId = $this->route('modifierGroup')->id ?? 0;

        return [
            'name' => ['required', 'string', 'max:100'],
            'is_required' => ['required', 'boolean'],
            'min_select' => ['required', 'integer', 'min:0', 'lte:max_select'],
            'max_select' => ['required', 'integer', 'min:1', 'max:50'],
            'options' => ['required', 'array', 'max:50'],
            // Only this group's live options may be updated by id.
            'options.*.id' => [
                'nullable', 'integer', 'distinct',
                Rule::exists('modifier_options', 'id')->where('modifier_group_id', $groupId)->whereNull('deleted_at'),
            ],
            'options.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'options.*.price_delta' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'options.*.is_default' => ['required', 'boolean'],
            'options.*.components' => ['nullable', 'array', 'max:20'],
            // Only a product that holds stock can be deducted (not a recipe), in whole units: stock is an integer.
            'options.*.components.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_composite', 0)],
            'options.*.components.*.qty' => ['required', 'integer', 'min:1', 'max:1000000000'],
        ];
    }

    /** The rules that span several fields. Runs even when a rule above failed, so it trusts no input shape. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $errors = $validator->errors();
            $required = $this->boolean('is_required');

            if ($required && (int) $this->input('min_select') < 1) {
                $errors->add('min_select', __('messages.modifiers.required_min'));
            }

            $options = array_filter((array) $this->input('options'), 'is_array');
            if ((int) $this->input('min_select') > count($options)) {
                $errors->add('options', __('messages.modifiers.too_few_options'));
            }
            $defaults = count(array_filter($options, fn (array $option) => filter_var($option['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN)));

            if ($defaults > 1) {
                $errors->add('options', __('messages.modifiers.one_default'));
            } elseif ($required && $defaults === 0) {
                $errors->add('options', __('messages.modifiers.default_required'));
            }

            foreach ($options as $index => $option) {
                $ids = array_filter(array_column((array) ($option['components'] ?? []), 'product_id'), 'is_scalar');

                if (count($ids) !== count(array_unique($ids))) {
                    $errors->add("options.$index.components", __('messages.modifiers.component_duplicate'));
                }
            }
        }];
    }

    // The app has no translated default validation messages, so every rule the form can reach is worded here (AC-20).
    public function messages(): array
    {
        return [
            'required' => __('messages.modifiers.field_required'),
            'integer' => __('messages.modifiers.whole_number'),
            'max' => __('messages.modifiers.too_large'),
            'exists' => __('messages.modifiers.gone'),
            'min_select.min' => __('messages.modifiers.min_negative'),
            'min_select.lte' => __('messages.modifiers.min_above_max'),
            'max_select.min' => __('messages.modifiers.max_below_one'),
            'options.required' => __('messages.modifiers.option_needed'),
            'options.*.price_delta.min' => __('messages.modifiers.price_negative'),
            'options.*.name.distinct' => __('messages.modifiers.option_name_taken'),
            'options.*.components.*.product_id.exists' => __('messages.modifiers.component_invalid'),
            'options.*.components.*.qty.min' => __('messages.modifiers.qty_below_one'),
        ];
    }
}
