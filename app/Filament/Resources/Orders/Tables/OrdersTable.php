<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Seller')
                    ->searchable(),
                TextColumn::make('client.email')
                    ->label('Buyer')
                    ->searchable(),
                TextColumn::make('offer.title')
                    ->label('Item')
                    ->placeholder('Tip'),
                TextColumn::make('amount')
                    ->money('IDR', 1)
                    ->sortable(),
                TextColumn::make('fee_percent')
                    ->label('Fee %')
                    ->suffix('%'),
                TextColumn::make('fee_amount')
                    ->label('Fee')
                    ->money('IDR', 1),
                TextColumn::make('net_amount')
                    ->label('To creator')
                    ->money('IDR', 1),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('payment_method')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('midtrans_order_id')
                    ->label('Midtrans order')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatus::class),
            ]);
    }
}
