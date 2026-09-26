<?php

namespace App\Filament\Resources\InstructorBalances\Pages;

use App\Filament\Resources\InstructorBalances\InstructorBalanceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditInstructorBalance extends EditRecord
{
    protected static string $resource = InstructorBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
