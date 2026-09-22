<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Guest = 'guest';

    /**
     * Cancelling a task is a manager's call. A field worker replying "لغو" to
     * a chase raises it with their manager instead of acting on it.
     */
    public function canCancelTasks(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالک',
            self::Admin => 'مدیر',
            self::Member => 'عضو',
            self::Guest => 'مهمان',
        };
    }
}
