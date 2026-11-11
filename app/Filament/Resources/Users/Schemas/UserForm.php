<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\AccountDeletion;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                Select::make('locale')
                    ->options(['en' => 'English', 'id' => 'Bahasa Indonesia'])
                    ->default('en')
                    ->required(),
                Select::make('status')
                    ->options(UserStatus::class)
                    ->default('active')
                    ->required(),
                DateTimePicker::make('suspended_at'),
                TextInput::make('suspended_reason'),
                // Account deletion: read-only here; the person asks in the app.
                DateTimePicker::make('deletion_requested_at')
                    ->label('Deletion requested')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(fn (?User $record) => ($at = $record !== null ? AccountDeletion::purgeAt($record) : null) !== null
                        ? 'Purged on '.$at->format('j M Y H:i').' UTC.'
                        : null)
                    ->visible(fn (?User $record) => $record?->deletion_requested_at !== null),
                Toggle::make('is_staff')
                    ->label('Internal staff (can access this panel)')
                    ->helperText('Staff sign in with a password; creators only via Google.'),
                TextInput::make('password')
                    ->password()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->visible(fn (callable $get): bool => (bool) $get('is_staff')),
            ]);
    }
}
