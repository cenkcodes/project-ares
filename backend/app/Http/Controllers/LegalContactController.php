<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

class LegalContactController extends Controller
{
    private function categories(): array
    {
        return [
            'general' => 'General',
            'copyright' => 'Copyright / Rights',
            'illegal_content' => 'Illegal / Prohibited Content',
            'privacy' => 'Privacy / Data Protection',
            'security' => 'Security',
            'advertising' => 'Advertising / Monetization',
        ];
    }

    public function show(Request $request): View
    {
        $categories = $this->categories();
        $selectedCategory = (string) $request->query('category', 'general');

        if (! array_key_exists($selectedCategory, $categories)) {
            $selectedCategory = 'general';
        }

        return view('pages.contact', compact('categories', 'selectedCategory'));
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->filled('company_website')) {
            return redirect()->route('pages.contact')
                ->with('status', 'Your message has been received.');
        }

        $categories = $this->categories();

        $validated = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', array_keys($categories))],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'page_url' => ['nullable', 'url', 'max:2048'],
            'message' => ['required', 'string', 'min:20', 'max:10000'],
        ]);

        $recipient = User::query()
            ->where('is_admin', true)
            ->whereNotNull('email')
            ->orderBy('id')
            ->value('email');

        if (! is_string($recipient) || $recipient === '') {
            Log::error('Xurvexa legal contact delivery failed: no admin recipient.');
            return back()->withInput()->withErrors([
                'message' => 'The contact service is temporarily unavailable. Please try again later.',
            ]);
        }

        $safeName = preg_replace('/[\r\n]+/', ' ', (string) $validated['name']) ?: 'Visitor';
        $safeSubject = preg_replace('/[\r\n]+/', ' ', (string) $validated['subject']) ?: 'Website message';
        $categoryLabel = $categories[$validated['category']] ?? 'General';

        $body = implode(PHP_EOL, [
            'Xurvexa website message',
            '',
            'Category: '.$categoryLabel,
            'Name: '.$safeName,
            'Email: '.$validated['email'],
            'Subject: '.$safeSubject,
            'Page URL: '.($validated['page_url'] ?: 'Not provided'),
            '',
            'Message:',
            (string) $validated['message'],
            '',
            'Submitted at: '.now()->toIso8601String(),
            'Source IP: '.($request->ip() ?: 'Unknown'),
            'User Agent: '.substr((string) $request->userAgent(), 0, 1000),
        ]);

        try {
            Mail::raw($body, function ($message) use ($recipient, $validated, $safeName, $safeSubject, $categoryLabel): void {
                $message
                    ->to($recipient)
                    ->replyTo($validated['email'], $safeName)
                    ->subject('[Xurvexa '.$categoryLabel.'] '.$safeSubject);
            });
        } catch (Throwable $exception) {
            Log::error('Xurvexa legal contact delivery failed.', [
                'category' => $validated['category'],
                'exception' => $exception->getMessage(),
            ]);

            return back()->withInput()->withErrors([
                'message' => 'The message could not be delivered right now. Please try again later.',
            ]);
        }

        return redirect()->route('pages.contact')
            ->with('status', 'Your message has been received. We will review it as soon as reasonably possible.');
    }
}
