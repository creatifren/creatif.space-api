<?php

namespace App\Filament\Resources\Withdrawals\Tables;

use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Support\Wallet;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class WithdrawalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Creator')
                    ->searchable(),
                TextColumn::make('wallet')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'affiliate' ? 'warning' : 'gray'),
                TextColumn::make('amount')
                    ->money(fn (Withdrawal $record) => $record->currency, 100)
                    ->sortable(),
                TextColumn::make('paypal_email')
                    ->label('PayPal')
                    ->copyable(),
                TextColumn::make('payout_reference')
                    ->label('PayPal reference')
                    ->placeholder('—')
                    ->copyable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('processed_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('failure_reason')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(WithdrawalStatus::class),
            ])
            ->recordActions([
                Action::make('markProcessing')
                    ->label('Start')
                    ->visible(fn (Withdrawal $record) => $record->status === WithdrawalStatus::Pending)
                    ->requiresConfirmation()
                    ->action(fn (Withdrawal $record) => $record
                        ->forceFill(['status' => WithdrawalStatus::Processing])
                        ->save()),

                Action::make('markCompleted')
                    ->label('Mark paid')
                    ->color('success')
                    ->visible(fn (Withdrawal $record) => $record->isOpen())
                    ->schema([
                        TextInput::make('payout_reference')
                            ->label('PayPal transaction ID')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Only do this after PayPal shows the payout as sent. The money is already out of the creator’s balance.')
                    ->action(function (Withdrawal $record, array $data) {
                        DB::transaction(function () use ($record, $data): void {
                            $locked = Withdrawal::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                            if (! $locked->isOpen()) {
                                return;
                            }
                            $locked->forceFill([
                                'status' => WithdrawalStatus::Completed,
                                'processed_at' => now(),
                                'payout_reference' => $data['payout_reference'],
                            ])->save();
                        });

                        Notification::make()
                            ->title('Marked as paid')
                            ->success()
                            ->send();
                    }),

                Action::make('markFailed')
                    ->label('Mark failed')
                    ->color('danger')
                    ->visible(fn (Withdrawal $record) => $record->isOpen())
                    ->schema([
                        TextInput::make('reason')
                            ->label('What went wrong?')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('The amount goes back into the creator’s balance as a new ledger line.')
                    ->action(function (Withdrawal $record, array $data) {
                        DB::transaction(function () use ($record, $data): void {
                            // A double click must not return the money twice.
                            $locked = Withdrawal::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                            if (! $locked->isOpen()) {
                                return;
                            }
                            $locked->forceFill([
                                'status' => WithdrawalStatus::Failed,
                                'processed_at' => now(),
                                'failure_reason' => $data['reason'],
                            ])->save();

                            // The wallet comes from the row, never a default.
                            Wallet::credit(
                                $locked->user,
                                WalletTransactionType::WithdrawalFailedCredit,
                                $locked->amount,
                                'withdrawal',
                                $locked->id,
                                $data['reason'],
                                wallet: $locked->wallet,
                            );
                        });

                        Notification::make()
                            ->title('Money returned to the balance')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
