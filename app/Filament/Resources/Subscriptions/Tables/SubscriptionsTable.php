<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Enums\SubscriptionStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Creator')
                    ->searchable(),
                TextColumn::make('user.email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->badge(),
                TextColumn::make('billing_period')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('seats'),
                TextColumn::make('current_period_end')
                    ->label('Runs until')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('grace_ends_at')
                    ->label('Grace until')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('cancelled_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(SubscriptionStatus::class),
                SelectFilter::make('plan')
                    ->relationship('plan', 'name'),
            ]);
    }
}
