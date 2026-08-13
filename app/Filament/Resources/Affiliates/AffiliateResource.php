<?php

namespace App\Filament\Resources\Affiliates;

use App\Filament\Resources\Affiliates\Pages\ListAffiliates;
use App\Filament\Resources\Affiliates\Tables\AffiliatesTable;
use App\Models\Affiliate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The application queue. The program is invite-only, so every row here is
 * somebody waiting for a person to read what they wrote.
 *
 * No create and no edit: an affiliate row is made by the apply form, and
 * the referral code must never be edited afterwards — changing it would
 * rewrite where past signups came from.
 */
class AffiliateResource extends Resource
{
    protected static ?string $model = Affiliate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return AffiliatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAffiliates::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The badge is a work queue: how many people are waiting for an answer.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Affiliate::query()->where('status', 'applied')->count();

        return $waiting > 0 ? (string) $waiting : null;
    }
}
