@use('App\Modules\Core\Contracts\Enums\CommitmentStatus')

{{--
    Status of a payment commitment as a chip (<x-cartera::commitment-status>):
    pending is informative, fulfilled is good, broken is bad, partially paid
    is a warning (part of it is still owed) and cancelled is neutral. The
    text always names the status, so color never carries it alone.
--}}
@props(['status'])

@php
    $tone = match ($status) {
        CommitmentStatus::Pending => 'info',
        CommitmentStatus::Fulfilled => 'success',
        CommitmentStatus::Broken => 'danger',
        CommitmentStatus::Cancelled => 'neutral',
        CommitmentStatus::PartiallyPaid => 'warning',
    };
@endphp

<x-ui.chip :tone="$tone" :attributes="$attributes">{{ $status->label() }}</x-ui.chip>
