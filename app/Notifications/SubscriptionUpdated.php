<?php

namespace App\Notifications;

use App\Models\User;

/** Premium started, a renewal payment is failing, renewal was turned off, or premium ended. */
class SubscriptionUpdated extends AppNotification
{
    public const ACTIVATED = 'subscription_activated';

    public const BILLING_ISSUE = 'subscription_billing_issue';

    public const RENEWAL_OFF = 'subscription_renewal_off';

    public const EXPIRED = 'subscription_expired';

    public function __construct(public string $event, public ?string $expiresOn = null)
    {
        parent::__construct();
        // The purchase screen already confirms a new subscription on the device that bought it.
        $this->push = $event !== self::ACTIVATED;
    }

    public function kind(): string
    {
        return $this->event;
    }

    public function title(User $user): string
    {
        return match ($this->event) {
            self::ACTIVATED => 'Welcome to Sira Premium',
            self::BILLING_ISSUE => 'Payment problem with your subscription',
            self::RENEWAL_OFF => 'Premium won\'t renew',
            default => 'Your Premium subscription has ended',
        };
    }

    public function body(User $user): string
    {
        $until = $this->expiresOn ? " on {$this->expiresOn}" : '';

        return match ($this->event) {
            self::ACTIVATED => 'The AI coach, advanced analytics, reports, and unlimited habits and goals are now unlocked.',
            self::BILLING_ISSUE => 'The store couldn\'t renew your subscription. Update your payment method to keep Premium.',
            self::RENEWAL_OFF => "Auto-renew is off, so Premium ends{$until}. You can turn it back on in the store.",
            default => 'Your tasks, habits, and history are safe. Resubscribe any time to unlock Premium again.',
        };
    }

    public function route(): string
    {
        return '/premium';
    }

    public function icon(): string
    {
        return $this->event === self::ACTIVATED ? 'workspace_premium' : 'credit_card';
    }
}
