@extends('pdf.layout')

@section('title', 'Offer of admission '.$application->number)

@section('content')
<p>{{ $issued }}</p>
<h1>Offer of Admission</h1>

<p>Dear {{ $applicant->fullName() }},</p>
<p>Congratulations! On behalf of the Office of Admissions, we are pleased to offer you admission as a first-year student for {{ $application->period->label }}.</p>

<table class="grid" style="margin: 14px 0;">
    <tr><td class="label">Applicant</td><td>{{ $applicant->fullName() }}</td></tr>
    <tr><td class="label">Application ID</td><td>{{ $application->number }}</td></tr>
    <tr><td class="label">Program</td><td>{{ $program?->name }}</td></tr>
    <tr><td class="label">Campus</td><td>{{ $application->campus ?: 'Main Campus' }}</td></tr>
    <tr><td class="label">Accept by</td><td><strong>{{ $deadline }}</strong></td></tr>
    @if ($accepted)
        <tr><td class="label">Accepted on</td><td>{{ $accepted }}</td></tr>
    @endif
</table>

<p>To secure your slot, accept this offer in the applicant portal on or before the date above. Slots are limited: an offer that is not accepted by the deadline is forfeited.</p>
<p>After accepting, submit your medical requirements in the portal. The university clinic will verify them to complete your admission. Please bring the original copies of all documents you uploaded at enrollment.</p>
<p>We look forward to seeing you on campus.</p>

<p style="margin-top: 28px;"><strong>Office of Admissions</strong><br>Pamantasan ng Lungsod ng Pasig</p>
@endsection
