<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\PlanResource;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    /**
     * No Delete: a plan with subscriptions behind it is restricted at the
     * database anyway, and retiring is what `is_active` is for.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
