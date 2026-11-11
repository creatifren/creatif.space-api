<?php

namespace App\Filament\Resources\MediaUploadFlags;

use App\Filament\Resources\MediaUploadFlags\Pages\ListMediaUploadFlags;
use App\Models\MediaUploadFlag;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * INTERNAL: accounts that keep hitting upload caps (daily budget, presign
 * rate, per-file caps, quotas). A list to review — nothing here bans
 * anyone, and nothing about these limits is ever shown to users.
 */
class MediaUploadFlagResource extends Resource
{
    protected static ?string $model = MediaUploadFlag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Upload flags';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label('User')->searchable(),
                TextColumn::make('user.ulid')->label('ULID')->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hits')->label('Hits (window)')->sortable(),
                TextColumn::make('total_hits')->label('Hits (all time)')->sortable(),
                TextColumn::make('last_reason')->badge(),
                TextColumn::make('last_hit_at')->dateTime()->sortable(),
                TextColumn::make('flagged_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->defaultSort('last_hit_at', 'desc')
            ->filters([
                Filter::make('flagged')
                    ->label('Flagged only')
                    ->query(fn (Builder $q) => $q->whereNotNull('flagged_at'))
                    ->default(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaUploadFlags::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = MediaUploadFlag::query()->whereNotNull('flagged_at')->count();

        return $n > 0 ? (string) $n : null;
    }
}
