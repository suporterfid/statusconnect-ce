<?php

namespace App\Application\StatusPages\Subscriptions;

use App\Domain\StatusPage\StatusPageLocale;
use App\Mail\StatusPageSubscriberMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Double opt-in email subscriptions to a status page (#92).
 *
 * The public answer to a subscribe request is always the same, so the form
 * never tells whether an address is subscribed. Subscriber addresses are never
 * returned by any API.
 */
final class SubscriptionService
{
    /** A page that accepts subscriptions: enabled by its operator and not private. */
    public function subscribablePage(string $slug): ?object
    {
        return DB::table('status_pages')
            ->where('slug', $slug)
            ->where('subscriptions_enabled', true)
            ->whereIn('visibility', ['public', 'unlisted'])
            ->first();
    }

    /**
     * Records a pending subscription and emails the confirmation link. Silently
     * does nothing when the address is already confirmed, when a link was sent
     * in the last 10 minutes, or when the page reached its limits.
     */
    public function subscribe(object $page, string $email): void
    {
        $email = Str::lower(trim($email));
        $now = now();

        $existing = DB::table('status_page_subscribers')->where('status_page_id', $page->id)->where('email', $email)->first();
        if ($existing !== null) {
            if ($existing->confirmed_at !== null
                || ($existing->confirmation_sent_at !== null && $now->diffInMinutes($existing->confirmation_sent_at, true) < 10)) {
                return;
            }
            $subscriber = $existing;
        } else {
            $limits = config('status_page.subscriptions');
            $recent = DB::table('status_page_subscribers')
                ->where('status_page_id', $page->id)
                ->whereNull('confirmed_at')
                ->where('created_at', '>=', $now->copy()->subHour())
                ->count();
            $total = DB::table('status_page_subscribers')->where('status_page_id', $page->id)->count();
            if ($recent >= (int) $limits['new_per_page_per_hour'] || $total >= (int) $limits['max_per_page']) {
                Log::info('status_page.subscribe_limited', ['page' => $page->slug]);

                return;
            }

            $id = DB::table('status_page_subscribers')->insertGetId([
                'public_id' => 'sub_'.Str::lower((string) Str::ulid()),
                'status_page_id' => $page->id,
                'email' => $email,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $subscriber = DB::table('status_page_subscribers')->find($id);
        }

        $hours = (int) config('status_page.subscriptions.confirmation_hours', 48);
        $url = url(URL::temporarySignedRoute('status.subscription.confirm', $now->copy()->addHours($hours), [
            'slug' => $page->slug,
            'subscriber' => $subscriber->public_id,
        ], absolute: false));

        $this->withPageLocale($page, function () use ($page, $email, $url, $hours): void {
            $this->send($email, new StatusPageSubscriberMail('confirm', __('status.subscribe_mail_confirm_subject', ['page' => $page->title]), [
                'pageTitle' => $page->title,
                'pageUrl' => url('/status/'.$page->slug),
                'url' => $url,
                'hours' => $hours,
            ] + StatusPageSubscriberMail::branding($page)));
        });

        DB::table('status_page_subscribers')->where('id', $subscriber->id)->update(['confirmation_sent_at' => $now, 'updated_at' => $now]);
    }

    public function find(object $page, string $publicId): ?object
    {
        return DB::table('status_page_subscribers')->where('status_page_id', $page->id)->where('public_id', $publicId)->first();
    }

    public function confirm(object $subscriber): void
    {
        if ($subscriber->confirmed_at === null) {
            DB::table('status_page_subscribers')->where('id', $subscriber->id)->update(['confirmed_at' => now(), 'updated_at' => now()]);
        }
    }

    public function unsubscribe(object $subscriber): void
    {
        DB::table('status_page_subscribers')->where('id', $subscriber->id)->delete();
    }

    /** Permanent unsubscribe link, authenticated by an HMAC of the subscriber id and address. */
    public function unsubscribeUrl(object $page, object $subscriber): string
    {
        return url('/status/'.$page->slug.'/subscriptions/'.$subscriber->public_id.'/unsubscribe/'.$this->unsubscribeToken($subscriber));
    }

    public function validUnsubscribeToken(object $subscriber, string $token): bool
    {
        return hash_equals($this->unsubscribeToken($subscriber), $token);
    }

    /** Deletes subscriptions never confirmed within the link's lifetime. */
    public function purgeUnconfirmed(): int
    {
        $hours = (int) config('status_page.subscriptions.confirmation_hours', 48);

        return DB::table('status_page_subscribers')
            ->whereNull('confirmed_at')
            ->where('created_at', '<', now()->subHours($hours + 1))
            ->delete();
    }

    public function confirmedCount(int $pageId): int
    {
        return DB::table('status_page_subscribers')->where('status_page_id', $pageId)->whereNotNull('confirmed_at')->count();
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function withPageLocale(object $page, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale(StatusPageLocale::normalize((string) $page->locale));
        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    private function unsubscribeToken(object $subscriber): string
    {
        $key = (string) config('app.key');

        return substr(hash_hmac('sha256', 'status-unsubscribe|'.$subscriber->public_id.'|'.$subscriber->email, $key), 0, 40);
    }

    private function send(string $to, StatusPageSubscriberMail $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (Throwable $e) {
            Log::warning('status_page.subscriber_mail_failed', ['kind' => $mail->kind, 'error' => $e->getMessage()]);
        }
    }
}
