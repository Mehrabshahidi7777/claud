<?php

namespace App\Enums;

/**
 * What kind of group this workspace is, and therefore what the product is.
 *
 * Until this existed the three plans were only three prices: a family paid
 * less for the same screens, and then met "صلاحیت پیمانکاری" and "تشدید به
 * مدیر" on their second day. A plan that does not change the product is a
 * discount, not a plan.
 *
 * Two things follow from the type. Which modules exist at all — a household
 * has no receivables to chase and no meetings to minute — and how the
 * follow-up ladder behaves, because escalating to somebody's manager is a
 * sentence that only makes sense at work.
 */
enum WorkspaceType: string
{
    case Corporate = 'corporate';
    case Family = 'family';
    case Friends = 'friends';

    public function label(): string
    {
        return match ($this) {
            self::Corporate => 'شرکتی',
            self::Family => 'خانوادگی',
            self::Friends => 'دوستانه',
        };
    }

    public function tagline(): string
    {
        return match ($this) {
            self::Corporate => 'کارها، جلسه‌ها، تأییدیه‌ها، قراردادها و پول — یک‌جا',
            self::Family => 'قبض‌ها، سرویس‌ها و کارهای خانه، بدون اینکه کسی یادآوری کند',
            self::Friends => 'خرج‌های مشترک و قرارهای گروهی — بدون اینکه کسی مجبور شود بپرسد',
        };
    }

    /**
     * Modules this type gets. Everything not listed is not merely hidden —
     * its routes answer 404, because a page that exists but is invisible is
     * a page somebody eventually reaches by URL.
     *
     * @return list<string>
     */
    public function modules(): array
    {
        return match ($this) {
            self::Corporate => [
                'tasks', 'meetings', 'approvals', 'finance', 'recurring',
                'contracts', 'departments', 'reports', 'members', 'notifications',
                'billing',
            ],

            // No invoices to chase, no minutes to take, no leave to approve.
            // What is left is the part a household actually has: things that
            // come round, and somebody who has to do them. Insurance and
            // licences fit "recurring" perfectly well — an annual renewal is
            // a recurrence, and calling it a contract would be dressing a
            // household up as a company.
            self::Family => [
                'tasks', 'recurring', 'reports', 'members', 'notifications', 'billing',
            ],

            // The one module that makes this plan a plan. A group's real
            // pain is not chores — it is the question after every trip, and
            // the fact that nobody wants to be the one who asks.
            self::Friends => [
                'tasks', 'recurring', 'settlements', 'reports', 'members',
                'notifications', 'billing',
            ],
        };
    }

    public function has(string $module): bool
    {
        return in_array($module, $this->modules(), true);
    }

    /**
     * Whether an unanswered chase climbs to somebody else.
     *
     * Only at work. A family has no reporting line, and a system that texts
     * someone's spouse because they have not taken the bins out has
     * misunderstood the household it was invited into — that is the message
     * that gets the product uninstalled.
     */
    public function hasEscalation(): bool
    {
        return $this === self::Corporate;
    }

    /** What the people in this workspace are called on screen. */
    public function memberWord(): string
    {
        return match ($this) {
            self::Corporate => 'اعضا',
            self::Family => 'اعضای خانواده',
            self::Friends => 'دوستان',
        };
    }

    public function managerWord(): string
    {
        return match ($this) {
            self::Corporate => 'مدیر',
            self::Family => 'سرپرست',
            self::Friends => 'هماهنگ‌کننده',
        };
    }

    /**
     * The plan key in config/payment.php, kept in step so a workspace cannot
     * be a family that is billed as a company.
     */
    public function planKey(): string
    {
        return $this->value;
    }
}
