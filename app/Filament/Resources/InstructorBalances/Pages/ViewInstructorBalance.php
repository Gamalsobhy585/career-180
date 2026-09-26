<?php
namespace App\Filament\Resources\InstructorBalances\Pages;

use App\Filament\Resources\InstructorBalances\InstructorBalanceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInstructorBalance extends ViewRecord
{
    protected static string $resource = InstructorBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return []; // no "edit" button
    }
}