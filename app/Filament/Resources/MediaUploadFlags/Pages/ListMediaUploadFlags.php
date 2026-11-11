<?php

namespace App\Filament\Resources\MediaUploadFlags\Pages;

use App\Filament\Resources\MediaUploadFlags\MediaUploadFlagResource;
use Filament\Resources\Pages\ListRecords;

class ListMediaUploadFlags extends ListRecords
{
    protected static string $resource = MediaUploadFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
