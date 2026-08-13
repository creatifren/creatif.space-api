<?php

namespace App\Filament\Resources\Affiliates\Tables;

use App\Enums\AffiliateStatus;
use App\Models\Affiliate;
use App\Notifications\AffiliateApproved;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AffiliatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Applied')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Creator')
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('code')
                    ->badge()
                    ->copyable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('tier_percent')
                    ->label('Rate')
                    ->suffix('%'),
                TextColumn::make('paid_referrals_count')
                    ->label('Paid')
                    ->sortable(),
                // What they actually wrote — the reason this queue is read
                // by a person rather than approved automatically.
                TextColumn::make('application.link')
                    ->label('Link')
                    ->url(fn (Affiliate $record): ?string => $record->application['link'] ?? null)
                    ->openUrlInNewTab(),
                TextColumn::make('application.audience')
                    ->label('Audience')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('approved_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(AffiliateStatus::class),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->visible(fn (Affiliate $record) => $record->status !== AffiliateStatus::Approved)
                    ->requiresConfirmation()
                    ->modalDescription('They get their link straight away, and /referral opens for them.')
                    ->action(function (Affiliate $record) {
                        $record->forceFill([
                            'status' => AffiliateStatus::Approved,
                            'approved_at' => now(),
                        ])->save();

                        $record->user->notify(new AffiliateApproved($record));

                        Notification::make()
                            ->title('Approved')
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->color('danger')
                    ->visible(fn (Affiliate $record) => $record->status === AffiliateStatus::Applied)
                    ->schema([
                        TextInput::make('reason')
                            ->label('Why?')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Affiliate $record, array $data) {
                        $record->forceFill([
                            'status' => AffiliateStatus::Rejected,
                            'rejection_reason' => $data['reason'],
                        ])->save();

                        Notification::make()
                            ->title('Rejected')
                            ->success()
                            ->send();
                    }),

                Action::make('suspend')
                    ->color('warning')
                    ->visible(fn (Affiliate $record) => $record->status === AffiliateStatus::Approved)
                    ->schema([
                        TextInput::make('reason')
                            ->label('Why?')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->requiresConfirmation()
                    // Suspension stops new attribution; commission already
                    // earned is still owed and still releases on schedule.
                    ->modalDescription('New signups stop counting. What they have already earned still pays out.')
                    ->action(function (Affiliate $record, array $data) {
                        $record->forceFill([
                            'status' => AffiliateStatus::Suspended,
                            'rejection_reason' => $data['reason'],
                        ])->save();

                        Notification::make()
                            ->title('Suspended')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
