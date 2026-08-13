<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\PlanResource;
use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    /**
     * No Create action: the three plan keys are structural.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
