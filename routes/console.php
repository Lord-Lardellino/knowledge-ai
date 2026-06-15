<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\ProcessDocument;
use App\Models\Document;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('knowledge:reindex {--sync : Esegue subito i job invece di accodarli}', function () {
    $query = Document::withoutGlobalScopes()->select('id')->orderBy('id');
    $count = 0;

    $query->chunkById(100, function ($documents) use (&$count) {
        foreach ($documents as $document) {
            if ($this->option('sync')) {
                ProcessDocument::dispatchSync($document->id);
            } else {
                ProcessDocument::dispatch($document->id);
            }

            $count++;
        }
    });

    $this->info("Reindex avviato per {$count} documenti.");
})->purpose('Reindicizza i documenti con il provider embedding configurato');
