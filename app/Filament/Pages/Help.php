<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class Help extends Page
{
    protected string $view = 'filament.pages.help';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?int $navigationSort = 90;

    public static function getNavigationLabel(): string
    {
        return __('help.page.navigation_label');
    }

    public static function getNavigationGroup(): string | UnitEnum | null
    {
        return __('help.page.navigation_group');
    }

    public function getTitle(): string
    {
        return __('help.page.title');
    }

    public function getHeading(): string
    {
        return __('help.page.heading');
    }
}
