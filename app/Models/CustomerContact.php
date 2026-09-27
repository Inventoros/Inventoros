<?php

declare(strict_types=1);

namespace App\Models;

use App\Mail\PortalPasswordResetEmail;
use App\Models\Auth\Organization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * A person at a customer company who can sign in to the customer portal.
 *
 * Authenticates on the `customer` guard, never the staff `web` guard. Like
 * User, this model deliberately does NOT use BelongsToOrganization: it is
 * resolved during authentication, where the organization scope would recurse
 * into the guard that is resolving it. Every query for contacts is scoped by
 * organization_id (and customer_id) explicitly instead.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $customer_id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property Carbon|null $invited_at
 * @property int|null $invited_by
 * @property Carbon|null $activated_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $revoked_at
 * @property-read Organization $organization
 * @property-read Customer $customer
 * @property-read User|null $inviter
 */
class CustomerContact extends Authenticatable
{
    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'customer_id',
        'name',
        'email',
        'password',
        'invited_at',
        'invited_by',
        'activated_at',
        'last_login_at',
        'revoked_at',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Emails are compared case-insensitively everywhere; store them lowercased.
     */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : mb_strtolower(trim($value));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The contact's customer, resolved without the staff OrganizationScope
     * (which keys off whoever the default guard's user is). Callers that
     * authorize on it check the organization explicitly; see canSignIn().
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return self::STATUS_REVOKED;
        }

        return $this->password === null ? self::STATUS_INVITED : self::STATUS_ACTIVE;
    }

    /**
     * Whether this contact may hold a portal session: not revoked, has set a
     * password, and belongs to an active, non-deleted customer.
     */
    public function canSignIn(): bool
    {
        if ($this->status() !== self::STATUS_ACTIVE) {
            return false;
        }

        $customer = $this->customer;

        return $customer !== null
            && (int) $customer->organization_id === (int) $this->organization_id
            && $customer->is_active;
    }

    /**
     * Reset tokens are keyed per organization: the same address can be a
     * contact at two organizations, and a token issued by one must never
     * reset the other.
     */
    public function getEmailForPasswordReset(): string
    {
        return $this->organization_id.'|'.$this->email;
    }

    /**
     * Send the portal's own reset email (org branded, portal URL) instead of
     * the framework notification, which links to the staff reset page.
     */
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this->email)->queue(new PortalPasswordResetEmail($this, (string) $token));
    }
}
