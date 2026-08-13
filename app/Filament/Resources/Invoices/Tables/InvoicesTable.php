<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->searchable(),
                TextColumn::make('subscription.user.name')
                    ->label('Creator')
                    ->searchable(),
                TextColumn::make('subscription.plan.name')
                    ->label('Plan')
                    ->badge(),
                TextColumn::make('amount')
                    ->money('IDR', 1)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('payment_method')
                    ->placeholder('—'),
                TextColumn::make('paid_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('midtrans_order_id')
                    ->label('Midtrans order')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(InvoiceStatus::class),
            ]);
    }
}
