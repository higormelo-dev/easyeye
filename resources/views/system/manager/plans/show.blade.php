<fieldset>
    <table class="table">
        <tbody>
            <tr>
                <th width="25%">{{ __('actions.name') }}</th>
                <td>{{ $plan->name }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.description') }}</th>
                <td>{{ $plan->description ?: '-' }}</td>
            </tr>
            <tr>
                <th>{{ __('actions.price') }}</th>
                <td>R$ {{ number_format((float) $plan->price, 2, ',', '.') }}</td>
            </tr>
            <tr>
                <th>{{ __('actions.billing_cycle') }}</th>
                <td>{{ $plan->billing_cycle->label() }}</td>
            </tr>
            <tr>
                <th>{{ __('actions.sort_order') }}</th>
                <td>{{ $plan->sort_order }}</td>
            </tr>
            <tr>
                <th>{{ __('actions.active') }}</th>
                <td>
                    @if($plan->active)
                        <span class="badge bg-success">{{ __('forms.yes') }}</span>
                    @else
                        <span class="badge bg-secondary">{{ __('forms.no') }}</span>
                    @endif
                </td>
            </tr>
            <tr>
                <th>{{ __('actions.created_at') }}</th>
                <td>{{ $plan->created_at->format('d/m/Y H:i') }}</td>
            </tr>

            @if($plan->features->isNotEmpty())
                <tr>
                    <th class="bg-light" colspan="2">
                        <small class="text-muted">{{ __('actions.features') }}</small>
                    </th>
                </tr>
                @foreach($plan->features as $feature)
                    @php $key = $feature->feature; @endphp
                    @if($key instanceof \App\Enums\FeatureKey)
                        <tr>
                            <th>{{ $key->label() }}</th>
                            <td>
                                @if($key->isBoolean())
                                    @if($feature->value)
                                        <span class="badge bg-success">{{ __('forms.yes') }}</span>
                                    @else
                                        <span class="badge bg-secondary">{{ __('forms.no') }}</span>
                                    @endif
                                @else
                                    @if($feature->value == 0)
                                        <span class="text-muted">{{ __('actions.unlimited') }}</span>
                                    @else
                                        {{ $feature->value }}
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            @endif
        </tbody>
    </table>
</fieldset>
