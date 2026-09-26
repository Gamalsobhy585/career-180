<?php

namespace App\Filament\Resources\InstructorBalances\RelationManagers;

use App\Http\Enums\PayoutStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Payout History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(
                        fn (int $state): string =>
                            '$' . number_format($state / 100, 2)
                    ),

                TextColumn::make('status')
                    ->badge()
                    ->color(
                        fn (PayoutStatus $state): string => match ($state) {
                            PayoutStatus::Succeeded => 'success',
                            PayoutStatus::Failed => 'danger',
                            PayoutStatus::Processing,
                            PayoutStatus::Pending => 'warning',
                            PayoutStatus::Unknown => 'gray',
                        }
                    ),

                TextColumn::make('idempotency_key')
                    ->label('Idempotency Key')
                    ->limit(16)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('provider_reference')
                    ->label('Provider Ref')
                    ->placeholder('—'),

                TextColumn::make('attempted_at')
                    ->label('Attempted')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('confirmed_at')
                    ->label('Confirmed')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('attempted_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->bulkActions([]);
    }
}