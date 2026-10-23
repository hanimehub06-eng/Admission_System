@extends('pdf.layout')

@section('title', 'Exam permit '.$permit)

@section('content')
<table>
    <tr>
        <td>
            <h1>Entrance Exam Permit</h1>
            <div class="muted">{{ $application->period->label }}</div>
        </td>
        <td style="width: 180px; text-align: right;"><img src="{{ $qr }}" width="150" height="150" alt="QR code"></td>
    </tr>
</table>

<table class="grid" style="margin-top: 12px;">
    <tr>
        <td class="label">Permit number</td><td><strong>{{ $permit }}</strong></td>
        @if ($photo)
            <td rowspan="6" style="width: 120px; text-align: center;"><img src="{{ $photo }}" width="110" alt="Photo"></td>
        @endif
    </tr>
    <tr><td class="label">Applicant</td><td>{{ $applicant->fullName() }}</td></tr>
    <tr><td class="label">Application ID</td><td>{{ $application->number }}</td></tr>
    <tr><td class="label">Date</td><td>{{ $session->date->format('l, F j, Y') }}</td></tr>
    <tr><td class="label">Time</td><td>{{ $batch }} · {{ $time }}</td></tr>
    <tr><td class="label">Venue</td><td>{{ $session->venue }}</td></tr>
</table>

<h2>Bring on exam day</h2>
<ul>
    <li>This permit, printed, and your printed application form</li>
    <li>A valid ID or school ID</li>
    <li>Two (2) Mongol No. 2 pencils, two (2) black ballpens, eraser and sharpener</li>
    <li>Two (2) passport-size photos with name tag, and a water bottle</li>
</ul>
<p class="muted">Arrive 30 minutes early. The proctor scans the QR code to check you in. This permit is valid only for the date, time and venue above.</p>

<table class="sign">
    <tr>
        <td><div class="line">Applicant's signature over printed name</div></td>
        <td><div class="line">Proctor's signature over printed name</div></td>
    </tr>
</table>
@endsection
