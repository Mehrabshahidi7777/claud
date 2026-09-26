<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SponsorRequest extends FormRequest
{
    /**
     * The route is already behind the platform-admin middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:400'],
            'website_url' => ['nullable', 'url:http,https', 'max:255'],
            // No SVG: it is a document that can carry script, and a logo
            // served from our own origin would run it with our cookies.
            'logo' => [$this->isMethod('POST') ? 'required' : 'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'نام',
            'description' => 'توضیح',
            'website_url' => 'آدرس سایت',
            'logo' => 'لوگو',
            'sort_order' => 'ترتیب',
        ];
    }
}
