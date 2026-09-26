<?php

namespace App\Filament\Resources\InstructorBalances\Schemas;

use Filament\Infolists\Components\TextEntry;

class InstructorBalanceInfolist
{
    public static function schema(): array
    {
        return [
            TextEntry::make('id'),

            TextEntry::make('instructor.name'),

            TextEntry::make('total_earned_cents')
                ->numeric(),

            TextEntry::make('total_paid_cents')
                ->numeric(),

            TextEntry::make('total_outstanding_cents')
                ->numeric(),

            TextEntry::make('updated_at')
                ->dateTime(),
        ];
    }
}