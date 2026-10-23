<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $report['title'] }}</title>
<style>
    @page { margin: 18mm 14mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1d2433; }
    .school { font-size: 12pt; font-weight: bold; color: #0b5d3b; }
    h1 { font-size: 14pt; margin: 10px 0 2px; }
    .muted { color: #5a6475; font-size: 8.5pt; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #cfd5df; padding: 4px 5px; text-align: left; }
    th { background: #eef2f6; }
</style>
</head>
<body>
<div class="school">Pamantasan ng Lungsod ng Pasig · Office of Admissions</div>
<h1>{{ $report['title'] }}</h1>
<div class="muted">{{ $report['period'] }} · generated {{ now()->setTimezone(config('admission.display_timezone'))->format('F j, Y g:i A') }} by {{ $by }}</div>
<table>
    <thead><tr>@foreach ($report['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($report['rows'] as $row)
        <tr>@foreach ($row as $v)<td>{{ $v }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($report['columns']) }}">No data yet.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
