<fieldset>
    <div class="table-responsive">
        <table class="table">
            <tbody>
                <tr>
                    <th width="30%">{{ __('actions.entity') }}</th>
                    <td>{{ $subscription->entity?->name ?? '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.plan') }}</th>
                    <td>{{ $subscription->plan?->name ?? '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.status') }}</th>
                    <td>
                        <span class="badge {{ $subscription->status->badgeClass() }}">
                            {{ $subscription->status->label() }}
                        </span>
                    </td>
                </tr>

                <tr><th class="bg-light" colspan="2"><small class="text-muted">{{ __('actions.subscription') }}</small></th></tr>
                <tr>
                    <th>{{ __('actions.starts_at') }}</th>
                    <td>{{ $subscription->starts_at?->format('d/m/Y') ?? '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.ends_at') }}</th>
                    <td>
                        @if($subscription->ends_at)
                            {{ $subscription->ends_at->format('d/m/Y') }}
                        @else
                            <span class="text-muted">{{ __('actions.unlimited') }}</span>
                        @endif
                    </td>
                </tr>
                @if($subscription->trial_ends_at)
                    <tr>
                        <th>{{ __('actions.trial_ends_at') }}</th>
                        <td>{{ $subscription->trial_ends_at->format('d/m/Y') }}</td>
                    </tr>
                @endif
                @if($subscription->grace_period_ends_at)
                    <tr>
                        <th>{{ __('actions.grace_period_ends_at') }}</th>
                        <td>{{ $subscription->grace_period_ends_at->format('d/m/Y') }}</td>
                    </tr>
                @endif
                @if($subscription->cancelled_at)
                    <tr>
                        <th>{{ __('actions.cancelled_at') }}</th>
                        <td>{{ $subscription->cancelled_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @endif

                <tr>
                    <th>{{ __('actions.created_at') }}</th>
                    <td>{{ $subscription->created_at->format('d/m/Y H:i') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</fieldset>
