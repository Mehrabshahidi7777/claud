<?php

namespace App\Enums;

/**
 * The departments nearly every Iranian company has, plus a catch-all for the
 * ones that depend on what the company actually does.
 *
 * A fixed list rather than free text for the same reason expense categories
 * are fixed: a company given a blank field ends up with "بازرگانی"،
 * "بازرگاني" and "واحد بازرگانی" as three departments in its own report. The
 * catch-all takes the rest, and its name is typed once.
 */
enum DepartmentKind: string
{
    case Management = 'management';
    case Accounting = 'accounting';
    case Commercial = 'commercial';
    case HumanResources = 'human_resources';
    case Operations = 'operations';
    case Technical = 'technical';
    case Sales = 'sales';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Management => 'مدیریت',
            self::Accounting => 'مالی و حسابداری',
            self::Commercial => 'بازرگانی',
            self::HumanResources => 'منابع انسانی و اداری',
            self::Operations => 'عملیات و اجرا',
            self::Technical => 'فنی و مهندسی',
            self::Sales => 'فروش',
            self::Other => 'سایر',
        };
    }

    /**
     * The role this department's people usually need.
     *
     * A suggestion the form pre-selects, never a rule: putting somebody in
     * accounting should not silently hand them the books, and plenty of
     * accounting departments have a junior who only records expenses.
     */
    public function suggestedRole(): WorkspaceRole
    {
        return match ($this) {
            self::Management => WorkspaceRole::Admin,
            self::Accounting => WorkspaceRole::Finance,
            self::HumanResources => WorkspaceRole::HumanResources,
            self::Commercial, self::Sales => WorkspaceRole::Lead,
            default => WorkspaceRole::Member,
        };
    }
}
