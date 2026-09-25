<?php

namespace App\Enums;

/**
 * What somebody is in this workspace.
 *
 * Roles exist to hand out permissions in sets a person can hold in their
 * head. Nothing outside this file asks what role somebody has — everything
 * asks whether they may do a particular thing, so adding a role is editing
 * one table rather than hunting `=== Admin` through the codebase.
 *
 * The list is deliberately short. Six roles a manager can explain to a new
 * employee beat a permission editor nobody configures correctly: the company
 * that needs a seventh is better served by us adding it than by every
 * customer inventing their own.
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Lead = 'lead';
    case Finance = 'finance';
    case HumanResources = 'hr';
    case Member = 'member';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالک',
            self::Admin => 'مدیر',
            self::Lead => 'سرپرست بخش',
            self::Finance => 'مالی و حسابداری',
            self::HumanResources => 'منابع انسانی',
            self::Member => 'عضو',
            self::Guest => 'مهمان',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'همه‌ی دسترسی‌ها، از جمله اشتراک و پرداخت.',
            self::Admin => 'همه‌ی بخش‌ها به‌جز اشتراک.',
            self::Lead => 'کارهای بخش خودش را می‌بیند و تصمیم می‌گیرد.',
            self::Finance => 'هزینه، مطالبات و قراردادها — بدون دسترسی به اعضا.',
            self::HumanResources => 'اعضا، قراردادها و درخواست‌ها — بدون دسترسی به مالی.',
            self::Member => 'کارهای خودش.',
            self::Guest => 'فقط دیدن کارهای خودش.',
        };
    }

    /**
     * The permissions this role carries.
     *
     * Two separations here are the point of the whole scheme, and both come
     * straight from how companies actually work: the accountant sees the
     * money and not the staff file, and HR sees the staff file and not the
     * money. Rolling both into "admin" is what makes a customer refuse to put
     * their real contracts in.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),

            // Everything operational. Billing is the owner's, because it is
            // the one action that costs money and changes who the customer
            // is.
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $p) => $p !== Permission::ManageBilling,
            )),

            self::Lead => [
                Permission::ViewAllTasks,
                Permission::CancelTasks,
                Permission::ManageRecurring,
                Permission::ManageMeetings,
                Permission::DecideApprovals,
            ],

            self::Finance => [
                Permission::ViewFinance,
                Permission::ManageFinance,
                Permission::ViewContracts,
                Permission::ViewAllTasks,
            ],

            self::HumanResources => [
                Permission::ManageMembers,
                Permission::ViewContracts,
                Permission::ManageContracts,
                Permission::DecideApprovals,
                Permission::ViewAllTasks,
            ],

            self::Member => [
                Permission::ManageRecurring,
            ],

            self::Guest => [],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Roles that may be handed out from the members page. Owner is not among
     * them: there is one, it is whoever created the workspace, and changing
     * that is a different and more deliberate act than editing a row.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Lead, self::Finance, self::HumanResources, self::Member, self::Guest];
    }

    /**
     * Whether the task list shows the whole workspace or only the work this
     * person is part of. "عضو: کارهای خودش" on the roles page is a promise.
     */
    public function seesAllTasksIn(WorkspaceType $type): bool
    {
        // A household or a group of friends has no hierarchy to keep work
        // private from; there only a guest is limited to their own.
        if ($type !== WorkspaceType::Corporate) {
            return $this !== self::Guest;
        }

        return $this->can(Permission::ViewAllTasks);
    }

    /**
     * Whether someone in this role may hand out, or take away, the other.
     *
     * Managing members is not a licence to exceed yourself: HR can add people
     * but must not be able to make anyone — least of all themselves — an
     * admin with the finance access HR is denied.
     */
    public function covers(self $other): bool
    {
        return collect($other->permissions())->every(fn (Permission $permission) => $this->can($permission));
    }

    /*
    |--------------------------------------------------------------------------
    | Named checks kept for readability at the call sites that read better
    | with them. Each one is the permission question underneath.
    |--------------------------------------------------------------------------
    */

    public function canCancelTasks(): bool
    {
        return $this->can(Permission::CancelTasks);
    }

    public function canManageMembers(): bool
    {
        return $this->can(Permission::ManageMembers);
    }

    public function canSeeFinance(): bool
    {
        return $this->can(Permission::ViewFinance);
    }
}
