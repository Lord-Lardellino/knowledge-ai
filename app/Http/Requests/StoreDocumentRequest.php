<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreDocumentRequest — validazione upload documento.
 *
 * Formati supportati dalla pipeline di estrazione testo: PDF, DOCX, XLSX, TXT.
 * Limite dimensione configurabile via knowledge.max_upload_mb.
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // L'utente deve appartenere a un tenant per caricare documenti.
        return (bool) $this->user()?->tenant_id;
    }

    public function rules(): array
    {
        $maxKb = (int) config('knowledge.max_upload_mb', 25) * 1024;

        return [
            'file' => [
                'required',
                'file',
                "max:$maxKb",
                'mimes:pdf,docx,xlsx,txt',
            ],
            'title' => ['nullable', 'string', 'max:255'],
            // Pratica a cui agganciare il documento (verticale legale). Opzionale:
            // l'appartenenza al tenant è verificata nel controller.
            'matter_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Formato non supportato. Carica un PDF, DOCX, XLSX o TXT.',
            'file.max'   => 'Il file supera la dimensione massima consentita.',
        ];
    }
}
