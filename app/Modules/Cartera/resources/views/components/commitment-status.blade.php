@use('App\Modules\Core\Contracts\Enums\CommitmentStatus')

{{--
    Status of a payment commitment as a chip (<x-cartera::commitment-status>):
    pending is informative, fulfilled is good, broken is bad and cancelled is
    neutral. The text always names the status, so color never carries it alone.
--}}
@props(['status'])

@php
    $tone = match ($status) {
        CommitmentStatus::Pending => 'info',
        CommitmentStatus::Fulfilled => 'success',
        CommitmentStatus::Broken => 'danger',
        CommitmentStatus::Cancelled => 'neutral',
    };
@endphp

<x-ui.chip :tone="$tone" :attributes="$attributes">{{ $status->label() }}</x-ui.chip>
