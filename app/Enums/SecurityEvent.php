<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Security-relevant events recorded to the activity log.
 *
 * Every entry is written with `category = security` so the activity log page
 * can separate the security audit trail from ordinary record changes. The
 * enum value is stored in the `action` column.
 */
enum SecurityEvent: string
{
    public const CATEGORY = 'security';

    case LOGIN = 'auth.login';
    case LOGOUT = 'auth.logout';
    case LOGIN_FAILED = 'auth.failed';
    case LOCKOUT = 'auth.lockout';
    case PASSWORD_RESET_REQUESTED = 'auth.password_reset_requested';
    case PASSWORD_RESET = 'auth.password_reset';
    case TWO_FACTOR_ENABLED = 'two_factor.enabled';
    case TWO_FACTOR_DISABLED = 'two_factor.disabled';
    case TWO_FACTOR_FAILED = 'two_factor.failed';
    case API_TOKEN_CREATED = 'api_token.created';
    case API_TOKEN_REVOKED = 'api_token.revoked';
    case PERMISSION_DENIED = 'authz.denied';
    case USER_CREATED = 'user.created';
    case USER_ROLE_CHANGED = 'user.role_changed';
    case USER_ROLES_SYNCED = 'user.roles_synced';
    case ROLE_CREATED = 'role.created';
    case ROLE_UPDATED = 'role.updated';
    case ROLE_DELETED = 'role.deleted';
    case PORTAL_LOGIN = 'portal.login';
    case PORTAL_LOGIN_FAILED = 'portal.failed';
    case PORTAL_INVITE_SENT = 'portal.invite_sent';
    case PORTAL_ACCESS_REVOKED = 'portal.access_revoked';
    case ORGANIZATION_SWITCHED = 'organization.switched';
    case MEMBERSHIP_ADDED = 'membership.added';
    case MEMBERSHIP_ROLE_CHANGED = 'membership.role_changed';
    case MEMBERSHIP_REMOVED = 'membership.removed';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::LOGIN => 'Signed in',
            self::LOGOUT => 'Signed out',
            self::LOGIN_FAILED => 'Failed sign-in',
            self::LOCKOUT => 'Sign-in locked out',
            self::PASSWORD_RESET_REQUESTED => 'Password reset requested',
            self::PASSWORD_RESET => 'Password reset completed',
            self::TWO_FACTOR_ENABLED => 'Two-factor enabled',
            self::TWO_FACTOR_DISABLED => 'Two-factor disabled',
            self::TWO_FACTOR_FAILED => 'Two-factor challenge failed',
            self::API_TOKEN_CREATED => 'API token created',
            self::API_TOKEN_REVOKED => 'API token revoked',
            self::PERMISSION_DENIED => 'Permission denied',
            self::USER_CREATED => 'User created',
            self::USER_ROLE_CHANGED => 'User role changed',
            self::USER_ROLES_SYNCED => 'User custom roles changed',
            self::ROLE_CREATED => 'Role created',
            self::ROLE_UPDATED => 'Role updated',
            self::ROLE_DELETED => 'Role deleted',
            self::PORTAL_LOGIN => 'Customer portal sign-in',
            self::PORTAL_LOGIN_FAILED => 'Failed customer portal sign-in',
            self::PORTAL_INVITE_SENT => 'Customer portal invitation sent',
            self::PORTAL_ACCESS_REVOKED => 'Customer portal access revoked',
            self::ORGANIZATION_SWITCHED => 'Switched organization',
            self::MEMBERSHIP_ADDED => 'Organization member added',
            self::MEMBERSHIP_ROLE_CHANGED => 'Organization member role changed',
            self::MEMBERSHIP_REMOVED => 'Organization member removed',
        };
    }

    /**
     * Label/value pairs for front-end filter options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases()
        );
    }
}
