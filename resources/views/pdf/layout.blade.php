<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>@yield('title')</title>
<style>
    @page { margin: 28mm 20mm 22mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: #1d2433; line-height: 1.45; }
    .head { border-bottom: 2px solid #0b5d3b; padding-bottom: 8px; margin-bottom: 18px; }
    .head .school { font-size: 13pt; font-weight: bold; color: #0b5d3b; }
    .head .office { font-size: 9pt; color: #5a6475; }
    h1 { font-size: 16pt; margin: 0 0 4px; }
    h2 { font-size: 11pt; margin: 18px 0 6px; color: #0b5d3b; text-transform: uppercase; letter-spacing: .5px; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 5px 6px; vertical-align: top; text-align: left; }
    .grid td { border: 1px solid #cfd5df; }
    .grid th { border: 1px solid #cfd5df; background: #eef2f6; font-size: 9pt; }
    .label { color: #5a6475; font-size: 8.5pt; text-transform: uppercase; width: 32%; }
    .muted { color: #5a6475; font-size: 9pt; }
    .sign { margin-top: 42px; }
    .sign td { width: 50%; padding: 0 16px; text-align: center; }
    .sign .line { border-top: 1px solid #1d2433; padding-top: 4px; font-size: 9pt; }
    .foot { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 8pt; color: #8a93a3; text-align: center; }
</style>
</head>
<body>
<div class="head">
    <div class="school">Pamantasan ng Lungsod ng Pasig</div>
    <div class="office">Office of Admissions · Online Admission System</div>
</div>
@yield('content')
<div class="foot">Generated {{ now()->setTimezone(config('admission.display_timezone'))->format('F j, Y g:i A') }} · {{ $application->number }}</div>
</body>
</html>
