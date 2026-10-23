<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One file per request. Type is checked from the file's content, not its
 * name. Limit 5 MB (decision T8, matches the Nginx upload cap).
 */
class DocumentUploadRequest extends FormRequest
{
    public function rules(): array
    {
        $imageOnly = $this->route('key') === 'photo';
        $mimes = config($imageOnly ? 'admission.uploads.image_mimes' : 'admission.uploads.mimes');

        return [
            'file' => ['required', 'file', 'mimes:'.implode(',', $mimes), 'extensions:'.implode(',', $mimes), 'max:'.config('admission.uploads.max_kb')],
        ];
    }

    public function messages(): array
    {
        $mb = round(config('admission.uploads.max_kb') / 1024);

        return [
            'file.max' => "This file is over {$mb} MB. Compress it or choose a smaller file.",
            'file.mimes' => $this->route('key') === 'photo' ? 'Choose a JPG or PNG photo.' : 'Choose a PDF, JPG, or PNG file.',
            'file.extensions' => $this->route('key') === 'photo' ? 'Choose a JPG or PNG photo.' : 'Choose a PDF, JPG, or PNG file.',
        ];
    }
}
