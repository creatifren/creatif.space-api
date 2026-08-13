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
                TextColumn::make('amount')
                    ->money('IDR', 1)
                    ->sortable(),
                TextColumn::make('bank_code')
                    ->label('Bank')
                    ->badge(),
                TextColumn::make('account_number')
                    ->label('Account')
                    ->copyable(),
                TextColumn::make('account_name')
                    ->label('Name on account'),
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
                    ->requiresConfirmation()
                    ->modalDescription('Only do this once the transfer has actually left the bank. The money is already out of the creator’s balance.')
                    ->action(function (Withdrawal $record) {
                        $record->forceFill([
                            'status' => WithdrawalStatus::Completed,
                            'processed_at' => now(),
                        ])->save();

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
                        $record->forceFill([
                            'status' => WithdrawalStatus::Failed,
                            'processed_at' => now(),
                            'failure_reason' => $data['reason'],
                        ])->save();

                        // A new credit, not an undo: the debit stays on the
                        // record so the history says what happened.
                        Wallet::credit(
                            $record->user,
                            WalletTransactionType::WithdrawalFailedCredit,
                            $record->amount,
                            'withdrawal',
                            $record->id,
                            $data['reason'],
                        );

                        Notification::make()
                            ->title('Money returned to the balance')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
