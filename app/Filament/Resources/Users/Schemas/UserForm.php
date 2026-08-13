<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserStatus;
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
