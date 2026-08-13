<?php

namespace App\Filament\Resources\Withdrawals;

use App\Filament\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Filament\Resources\Withdrawals\Tables\WithdrawalsTable;
use App\Models\Withdrawal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The payout queue. Transfers are made by hand for now — Iris/Payouts is a
 * later wiring, and at this volume a person checking each account number
 * is the safer default.
 *
 * The two actions here move real money: marking one completed is a promise
 * that the transfer was actually made, and marking one failed puts the
 * amount back in the creator's balance.
 */
class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return WithdrawalsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWithdrawals::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The badge is a work queue: how many people are waiting for money.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = Withdrawal::query()->where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }
}
