<?php

namespace App\Enums;

/**
 * What somebody is allowed to do, named by the act rather than by the page.
 *
 * Roles answer to these; nothing in the application asks "is this person an
 * admin?". That question is the one that rots: the day a customer wants an
 * accountant who cannot add staff, every `role === Admin` in the codebase has
 * to be found and re-argued. Asking "may this person see the money?" survives
 * that conversation.
 */
enum Permission: string
{
    /* People and structure */
    case ManageMembers = 'manage_members';
    case ManageDepartments = 'manage_departments';
    case ManageBilling = 'manage_billing';

    /* Work */
    case ViewAllTasks = 'view_all_tasks';
    case CancelTasks = 'cancel_tasks';
    case ManageRecurring = 'manage_recurring';
    case ManageMeetings = 'manage_meetings';

    /* Money */
    case ViewFinance = 'view_finance';
    case ManageFinance = 'manage_finance';

    /* Paperwork and decisions */
    case ViewContracts = 'view_contracts';
    case ManageContracts = 'manage_contracts';
    case DecideApprovals = 'decide_approvals';

    public function label(): string
    {
        return match ($this) {
            self::ManageMembers => 'مدیریت اعضا',
            self::ManageDepartments => 'مدیریت بخش‌ها',
            self::ManageBilling => 'صورتحساب و اشتراک',
            self::ViewAllTasks => 'دیدن کارهای همه',
            self::CancelTasks => 'لغو کار',
            self::ManageRecurring => 'کارهای دوره‌ای',
            self::ManageMeetings => 'صورتجلسه',
            self::ViewFinance => 'دیدن اطلاعات مالی',
            self::ManageFinance => 'ثبت هزینه و مطالبه',
            self::ViewContracts => 'دیدن قراردادها',
            self::ManageContracts => 'ثبت و تمدید قرارداد',
            self::DecideApprovals => 'تصمیم درباره‌ی درخواست‌ها',
        };
    }

    /** Which heading this sits under on the roles page. */
    public function group(): string
    {
        return match ($this) {
            self::ManageMembers, self::ManageDepartments, self::ManageBilling => 'سازمان',
            self::ViewAllTasks, self::CancelTasks, self::ManageRecurring, self::ManageMeetings => 'کارها',
            self::ViewFinance, self::ManageFinance => 'مالی',
            self::ViewContracts, self::ManageContracts, self::DecideApprovals => 'قرارداد و تأییدیه',
        };
    }
}
