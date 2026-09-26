<?php

namespace App\Filament\Resources\InstructorBalances\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class InstructorBalanceForm
{
    public static function schema(): array
    {
        return [
            Select::make('instructor_id')
                ->relationship('instructor', 'name')
                ->required(),

            TextInput::make('total_earned_cents')
                ->required()
                ->numeric(),

            TextInput::make('total_paid_cents')
                ->required()
                ->numeric(),

            TextInput::make('total_outstanding_cents')
                ->required()
                ->numeric(),
        ];
    }
}