<x-filament-panels::page>
    <div class="space-y-6" wire:poll.5s="refreshStatus">
        <form wire:submit="runNow" class="space-y-6">
            {{ $this->form }}
            <div class="flex flex-wrap gap-3">
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="runNow">Run Now</x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="refreshStatus">Refresh</x-filament::button>
            </div>
        </form>

        @if($latestRun !== null)
            <section class="vh-card" aria-labelledby="vh-latest-title">
                <h2 id="vh-latest-title">Current / latest run #{{ $latestRun->id }}</h2>
                <x-filament::badge>{{ strtoupper($latestRun->status) }}</x-filament::badge>
                <dl class="vh-fields">
                    @foreach(['provider' => 'Provider', 'mode' => 'Mode', 'trigger' => 'Trigger', 'stage' => 'Stage', 'selected_count' => 'Selected', 'scanned_count' => 'Scanned', 'pass_count' => 'Pass', 'broken_count' => 'Broken', 'review_count' => 'Review', 'unverifiable_count' => 'Unverifiable', 'deactivated_count' => 'Deactivated'] as $field => $label)
                        <div><dt>{{ $label }}</dt><dd>{{ $latestRun->{$field} }}</dd></div>
                    @endforeach
                    @if($latestRun->process_id !== null)
                        <div><dt>Process ID</dt><dd>{{ $latestRun->process_id }}</dd></div>
                    @endif
                    @foreach(['created_at' => 'Created', 'started_at' => 'Started', 'heartbeat_at' => 'Heartbeat', 'finished_at' => 'Finished'] as $field => $label)
                        <div><dt>{{ $label }}</dt><dd>{{ $latestRun->{$field}?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
                    @endforeach
                </dl>
                @if($latestRun->message)
                    <p>{{ \Illuminate\Support\Str::limit($latestRun->message, 300) }}</p>
                @endif
                @if($latestRun->status === 'deferred')
                    <p>Deferred: this page does not promote or force deferred runs.</p>
                @endif
            </section>
        @endif

        <section class="vh-card" aria-labelledby="vh-history-title">
            <h2 id="vh-history-title">Recent runs</h2>
            <div class="vh-table-wrap">
                <table class="vh-table">
                    <thead><tr>
                        <th>ID</th><th>Provider</th><th>Mode</th><th>Trigger</th><th>Status</th>
                        <th>Selected</th><th>Scanned</th><th>Broken</th><th>Review</th><th>Unverifiable</th><th>Deactivated</th>
                        <th>Started / created</th><th>Finished</th><th>Launch</th>
                    </tr></thead>
                    <tbody>
                        @forelse($recentRuns as $run)
                            <tr wire:key="health-run-{{ $run->id }}">
                                <td>#{{ $run->id }}</td><td>{{ $run->provider }}</td><td>{{ $run->mode }}</td><td>{{ $run->trigger }}</td><td>{{ strtoupper($run->status) }}</td>
                                @foreach(['selected_count', 'scanned_count', 'broken_count', 'review_count', 'unverifiable_count', 'deactivated_count'] as $field)
                                    <td>{{ $run->{$field} }}</td>
                                @endforeach
                                <td>{{ ($run->started_at ?? $run->created_at)?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                <td>{{ $run->finished_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                <td>
                                    @if($this->canRetry($run))
                                        <x-filament::button type="button" color="gray" size="sm" wire:click="retryLaunch({{ $run->id }})" wire:loading.attr="disabled" wire:target="retryLaunch">Retry Launch</x-filament::button>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="14">No health runs yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <style>
        .vh-card { display: grid; gap: 1rem; padding: 1rem; border: 1px solid rgba(128,128,128,.22); border-radius: .85rem; }
        .vh-card h2 { font-weight: 700; }
        .vh-fields { display: grid; grid-template-columns: repeat(auto-fit,minmax(140px,1fr)); gap: .75rem; }
        .vh-fields dt { font-size: .75rem; opacity: .7; }
        .vh-fields dd, .vh-card p { overflow-wrap: anywhere; }
        .vh-table-wrap { overflow-x: auto; }
        .vh-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
        .vh-table th, .vh-table td { padding: .65rem; text-align: left; white-space: nowrap; border-bottom: 1px solid rgba(128,128,128,.18); }
    </style>
</x-filament-panels::page>
