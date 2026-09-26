<?php

namespace App\Filament\Resources\InstructorBalances;

use App\Filament\Resources\InstructorBalances\Pages;
use App\Filament\Resources\InstructorBalances\RelationManagers\PayoutsRelationManager;
use App\Filament\Resources\InstructorBalances\Schemas\InstructorBalanceForm;
use App\Filament\Resources\InstructorBalances\Schemas\InstructorBalanceInfolist;
use App\Models\InstructorBalance;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InstructorBalanceResource extends Resource
{
    protected static ?string $model = InstructorBalance::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Instructor Balances';

    protected static ?string $modelLabel = 'Instructor Balance';

    public static function form(Form $form): Form
    {
        return $form
            ->schema(InstructorBalanceForm::schema());
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema(InstructorBalanceInfolist::schema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('instructor.email')
                    ->label('Email')
                    ->searchable(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PayoutsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstructorBalances::route('/'),
            'view' => Pages\ViewInstructorBalance::route('/{record}'),
        ];
    }
}