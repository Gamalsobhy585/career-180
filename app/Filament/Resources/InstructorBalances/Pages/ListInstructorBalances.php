<?php
namespace App\Filament\Resources\InstructorBalances\Pages;

use App\Filament\Resources\InstructorBalances\InstructorBalanceResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorBalances extends ListRecords
{
    protected static string $resource = InstructorBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return []; // no "create" button
    }
}