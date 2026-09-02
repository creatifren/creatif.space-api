<?php

namespace App\Filament\Resources\Plans\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('key')
                    ->badge(),
                TextColumn::make('price_monthly')
                    ->label('Monthly')
                    ->money('IDR', 1)
                    ->sortable(),
                TextColumn::make('price_yearly')
                    ->label('Yearly')
                    ->money('IDR', 1)
                    ->sortable(),
                TextColumn::make('subscriptions_count')
                    ->label('Subscribers')
                    ->counts('subscriptions'),
                IconColumn::make('is_active')
                    ->label('Offered')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
