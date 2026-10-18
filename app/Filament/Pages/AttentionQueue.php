<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AttentionQuery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class AttentionQueue extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Attention queue';

    protected static ?string $title = 'Attention queue';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.attention-queue';

    /**
     * Buyers never see operational data.
     *
     * Uses the same predicate that gates the panel rather than a parallel
     * check, so the console cannot drift from the panel's own access rules.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->role?->canAccessPanel() ?? false;
    }

    public function getViewData(): array
    {
        $result = app(AttentionQuery::class)->allWithHealth();

        // Resolved in one query. Looking each owner up inside the row loop
        // would issue a query per exception.
        $ownerIds = $result['items']->pluck('ownerId')->filter()->unique();
        $ownerNames = $ownerIds->isEmpty()
            ? []
            : User::whereIn('id', $ownerIds)->pluck('name', 'id')->all();

        return [
            'items' => $result['items'],
            'failed' => $result['failed'],
            'ownerNames' => $ownerNames,
            'severityCounts' => $result['items']
                ->groupBy(fn ($item) => $item->severity->label())
                ->map->count(),
        ];
    }
}