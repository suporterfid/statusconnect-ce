<?php

namespace App\Http\Controllers\Public;

use App\Application\StatusPages\Subscriptions\SubscriptionService;
use App\Domain\StatusPage\StatusPageLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * Public subscribe, confirm and unsubscribe pages of a status page (#92).
 *
 * All of them live on the app host: the subscribe form on a custom domain posts
 * here. Confirmation and unsubscription take a button press (POST), so mail
 * scanners that open links do not act on them; unsubscribe also accepts the
 * RFC 8058 one-click POST from mail clients.
 */
final class StatusPageSubscriptionController
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function subscribe(Request $request, string $slug): Response
    {
        $page = $this->page($slug);

        // Honeypot: people never see this field.
        if (trim((string) $request->input('website', '')) === '') {
            $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
            $this->subscriptions->subscribe($page, $validated['email']);
        }

        return $this->message($page, __('status.subscribe_sent_title'), __('status.subscribe_sent_body', [
            'page' => $page->title,
            'hours' => (int) config('status_page.subscriptions.confirmation_hours', 48),
        ]));
    }

    public function confirmForm(Request $request, string $slug, string $subscriber): Response
    {
        $page = $this->page($slug);
        if (! $request->hasValidRelativeSignature() || $this->subscriptions->find($page, $subscriber) === null) {
            return $this->invalid($page);
        }

        return $this->message($page, __('status.subscribe_confirm_title'), __('status.subscribe_confirm_body', ['page' => $page->title]), [
            'action' => $request->getRequestUri(),
            'button' => __('status.subscribe_confirm_button'),
        ]);
    }

    public function confirm(Request $request, string $slug, string $subscriber): Response
    {
        $page = $this->page($slug);
        $row = $this->subscriptions->find($page, $subscriber);
        if (! $request->hasValidRelativeSignature() || $row === null) {
            return $this->invalid($page);
        }

        $this->subscriptions->confirm($row);

        return $this->message($page, __('status.subscribe_confirmed_title'), __('status.subscribe_confirmed_body', ['page' => $page->title]));
    }

    public function unsubscribeForm(string $slug, string $subscriber, string $token): Response
    {
        $page = $this->anyPage($slug);
        $row = $this->subscriptions->find($page, $subscriber);
        if ($row === null || ! $this->subscriptions->validUnsubscribeToken($row, $token)) {
            // Already removed, or a wrong link: either way this address gets nothing more from this link.
            return $this->message($page, __('status.unsubscribed_title'), __('status.unsubscribed_body', ['page' => $page->title]));
        }

        return $this->message($page, __('status.unsubscribe_title'), __('status.unsubscribe_body', ['page' => $page->title]), [
            'action' => url('/status/'.$page->slug.'/subscriptions/'.$row->public_id.'/unsubscribe/'.$token),
            'button' => __('status.unsubscribe_button'),
        ]);
    }

    public function unsubscribe(string $slug, string $subscriber, string $token): Response
    {
        $page = $this->anyPage($slug);
        $row = $this->subscriptions->find($page, $subscriber);
        if ($row !== null && $this->subscriptions->validUnsubscribeToken($row, $token)) {
            $this->subscriptions->unsubscribe($row);
        }

        return $this->message($page, __('status.unsubscribed_title'), __('status.unsubscribed_body', ['page' => $page->title]));
    }

    /** A page that accepts subscriptions now. */
    private function page(string $slug): object
    {
        $page = $this->subscriptions->subscribablePage($slug);
        abort_if($page === null, 404);
        App::setLocale(StatusPageLocale::normalize((string) $page->locale));

        return $page;
    }

    /** Unsubscribing works even after the operator turns subscriptions off or makes the page private. */
    private function anyPage(string $slug): object
    {
        $page = \Illuminate\Support\Facades\DB::table('status_pages')->where('slug', $slug)->first();
        abort_if($page === null, 404);
        App::setLocale(StatusPageLocale::normalize((string) $page->locale));

        return $page;
    }

    private function invalid(object $page): Response
    {
        return $this->message($page, __('status.subscribe_invalid_title'), __('status.subscribe_invalid_body'));
    }

    /** @param array{action: string, button: string}|null $form */
    private function message(object $page, string $title, string $body, ?array $form = null): Response
    {
        return response()->view('status.subscription', [
            'page' => $page,
            'title' => $title,
            'body' => $body,
            'form' => $form,
            'pageUrl' => $page->custom_domain ? 'https://'.$page->custom_domain : url('/status/'.$page->slug),
        ])->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }
}
