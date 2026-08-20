<?php

namespace App\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // The key is what code resolves plans by ("free") and what
                // checkout sends — changing it would break both.
                TextInput::make('key')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('name')
                    ->required(),
                TextInput::make('price_monthly')
                    ->label('Price / month (Rp)')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('price_yearly')
                    ->label('Price / year (Rp)')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('fee_percent')
                    ->label('Transaction fee (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(99.99)
                    ->step(0.01)
                    ->required(),
                TextInput::make('seat_price_monthly')
                    ->label('Extra seat / month (Rp)')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                KeyValue::make('quotas')
                    ->keyLabel('Limit')
                    ->valueLabel('Ceiling')
                    ->helperText('Leave a value empty for "no limit". Keys: storage_bytes, spaces_total, spaces_active, seats, custom_domains.'),
                KeyValue::make('features')
                    ->keyLabel('Feature')
                    ->valueLabel('On')
                    ->helperText('true / false. Keys: branding_removed, delivery_history, full_analytics, custom_fonts.'),
                Toggle::make('is_active')
                    ->label('Offered to new subscribers')
                    ->helperText('Switching this off retires the plan; existing subscriptions keep running.'),
                TextInput::make('sort_order')
                    ->numeric()
                    ->required(),
            ]);
    }
}
