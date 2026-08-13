<?php

namespace App\Filament\Resources\Commissions\Tables;

use App\Enums\CommissionStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Earned')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('referral.affiliate.user.name')
                    ->label('Affiliate')
                    ->searchable(),
                TextColumn::make('referral.referredUser.name')
                    ->label('Customer')
                    ->placeholder('—'),
                TextColumn::make('invoice.number')
                    ->label('Invoice')
                    ->toggleable(),
                TextColumn::make('installment_no')
                    ->label('Part')
                    ->formatStateUsing(fn (int $state): string => $state.' of 12'),
                TextColumn::make('tier_percent')
                    ->label('Rate')
                    ->suffix('%'),
                TextColumn::make('amount')
                    ->money('IDR', 1)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('hold_until')
                    ->label('Pays out')
                    ->date()
                    ->sortable(),
                TextColumn::make('released_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(CommissionStatus::class),
            ]);
    }
}
