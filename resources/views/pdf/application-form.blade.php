@extends('pdf.layout')

@section('title', 'Application form '.$application->number)

@section('content')
<table>
    <tr>
        <td>
            <h1>Application Form</h1>
            <div class="muted">{{ $application->period->label }} · Applicant ID {{ $application->applicant_no ?? 'issued once admission approves the application' }}</div>
            <div class="muted">Submitted {{ $application->submitted_at->setTimezone(config('admission.display_timezone'))->format('F j, Y') }}</div>
        </td>
        @if ($photo)
            <td style="width: 120px; text-align: right;"><img src="{{ $photo }}" width="110" alt="Photo"></td>
        @endif
    </tr>
</table>

<h2>Personal information</h2>
<table class="grid">
    <tr><td class="label">Name</td><td>{{ $applicant->fullName() }}</td></tr>
    <tr><td class="label">LRN</td><td>{{ $values['lrn'] }}</td></tr>
    <tr><td class="label">Birthdate · Sex</td><td>{{ $values['birthDate'] }} · {{ $values['sex'] }}</td></tr>
    <tr><td class="label">Email · Mobile</td><td>{{ $values['email'] }} · {{ $values['phone'] }}</td></tr>
    <tr><td class="label">Residency</td><td>{{ $applicant->is_resident ? 'Pasig resident · District '.$applicant->district.' · Brgy. '.$applicant->barangay : 'Not a Pasig resident' }}</td></tr>
    <tr><td class="label">Address</td><td>{{ $values['address'] }}</td></tr>
    <tr><td class="label">Guardian</td><td>{{ $applicant->has_guardian ? 'Lives with a guardian' : 'Lives with a parent' }}</td></tr>
</table>

<h2>Education</h2>
<table class="grid">
    <tr><td class="label">Last school</td><td>{{ $values['lastSchool'] }} ({{ $values['schoolType'] }})</td></tr>
    <tr><td class="label">Strand · Graduation</td><td>{{ $values['strand'] }} · {{ $values['gradYear'] }}</td></tr>
</table>

<h2>Program choices</h2>
<table class="grid">
    @foreach ([1, 2, 3] as $rank)
        @if ($values['program'.$rank])
            <tr><td class="label">Choice {{ $rank }}</td><td>{{ $values['program'.$rank] }}</td></tr>
        @endif
    @endforeach
</table>

<h2>Documentary requirements</h2>
<table class="grid">
    <tr><th>Requirement</th><th style="width: 22%;">Uploaded</th><th style="width: 22%;">Photocopy received</th></tr>
    @foreach ($documents as $requirement)
        @php($doc = $uploaded->get($requirement->id))
        <tr>
            <td>{{ $requirement->title }}</td>
            <td>{{ $doc ? 'Yes' : 'No' }}</td>
            <td>{{ $doc?->photocopy_received_at ? 'Yes' : '' }}</td>
        </tr>
    @endforeach
</table>

<p class="muted" style="margin-top: 14px;">I certify that the information above and the documents I uploaded are true and complete.</p>
<table class="sign">
    <tr>
        <td><div class="line">Applicant's signature over printed name</div></td>
        <td><div class="line">Received by (Office of Admissions)</div></td>
    </tr>
</table>
@endsection
