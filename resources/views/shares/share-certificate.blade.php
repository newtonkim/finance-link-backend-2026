<!DOCTYPE html>
<html lang="en">

@php
    use Illuminate\Support\Facades\Storage;

    $branding = App\Tenant\Modules\Settings\Models\SaccoBranding::current();

    $logoDataUri = null;
    $saccoName = $branding->sacco_name ?? 'Your Company Name';
    $saccoTagline = $branding->tagline ?? null;

    if (!empty($branding->logo_path)) {
        $logoAbsPath = Storage::disk('public')->path($branding->logo_path);

        if (file_exists($logoAbsPath)) {
            $ext = strtolower(pathinfo($logoAbsPath, PATHINFO_EXTENSION));

            $mime = match ($ext) {
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };

            $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoAbsPath));
        }
    }

    $name = explode(':', $data->salutation_name ?? '')[1] ?? $data->salutation_name ?? 'N/A';
@endphp

<head>
    <meta charset="UTF-8">
    <title>Share Certificate</title>

    <style>
        body {
            font-family: 'Georgia', 'Times New Roman', serif;
            background: #e5e7eb;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px;
        }

        .certificate {
            width: 900px;
            background: linear-gradient(145deg, #ffffff, #f9fafb);
            padding: 50px;
            border: 12px solid #0d9488;
            position: relative;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            border-radius: 20px;
        }

        .certificate::before {
            content: "";
            position: absolute;
            inset: 15px;
            border: 2px solid #0d9488;
            border-radius: 20px;
        }

        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.05;
            z-index: 0;
        }

        .content {
            position: relative;
            z-index: 1;
        }

        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }

        .box {
            border: 1px solid #0d9488;
            padding: 5px;
            width: 150px;
            height: 70px;
            text-align: center;
            background: #f9fafb;
        }

        .box label {
            font-size: 10px;
            text-transform: uppercase;
            color: #6b7280;
        }

        .box .value {
            font-size: 20px;
            font-weight: bold;
        }

        .title {
            text-align: center;
            flex: 1;
            padding: 0% 8%; 
        }

        .title h1 {
            font-size: 42px;
            letter-spacing: 3px;
            margin: 10px 0;
            font-family: 'Brush Script MT', cursive, 'Great Vibes', serif;
        }

        .title h2 {
            font-size: 18px;
            margin: 3px 0;
        }

        .tagline {
            font-size: 12px;
            color: #6b7280;
        }

        .subtitle {
            text-align: center;
            margin: 30px 0;
            font-size: 14px;
            line-height: 1.8;
        }

        .member-name {
            font-size: 22px;
            font-weight: bold;
            margin: 10px 0;
            text-transform: uppercase;
        }

        .details {
            text-align: center;
            font-size: 13px;
            margin-top: 7px;
        }

        table {
            width: 100%;
            margin-top: 30px;
            border-collapse: collapse;
        }

        th {
            background: #f3f4f6;
            padding: 10px;
            font-size: 11px;
            border-bottom: 2px solid #0d9488;
        }

        td {
            padding: 14px;
            text-align: center;
            font-size: 14px;
        }

        .footer {
            display: flex;
            justify-content: space-between;
            margin-top: 60px;
            align-items: flex-end;
        }

        .seal {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: radial-gradient(circle, #dc2626, #7f1d1d);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: bold;
            transform: rotate(-10deg);
        }

        .signatures {
            display: flex;
            gap: 60px;
        }

        .sig {
            text-align: center;
        }

        .sig-line {
            border-top: 1px solid #111;
            margin-top: 60px;
            padding-top: 8px;
            font-size: 11px;
        }
    </style>
</head>

<body>
<div class="certificate">

    {{-- WATERMARK --}}
    @if ($logoDataUri)
        <img src="{{ $logoDataUri }}" class="watermark" width="300">
    @endif

    <div class="content">

        <div class="header">
            <div class="box">
                <label>Shares</label>
                <div class="value">{{ number_format($data->share_no ?? 0) }}</div>
            </div>

            <div class="title">
                @if ($logoDataUri)
                    <img src="{{ $logoDataUri }}" height="60">
                @endif

                <h1>SHARE CERTIFICATE</h1>
                <h2>{{ $saccoName }}</h2>

                @if ($saccoTagline)
                    <div class="tagline">{{ $saccoTagline }}</div>
                @endif

                <div style="font-size:12px;">REG NO: 2001/123456/07</div>
                <div style="font-size:11px;">(Incorporated in Uganda)</div>
            </div>

            <div class="box">
                <label>Certificate No</label>
                <div class="value">{{ $data->id ?? '-' }}</div>
            </div>
        </div>

        <div class="subtitle">
            This is to certify that
            <div class="member-name">{{ $name }}</div>
            is the registered holder of shares in the above company.
        </div>

        <div class="details">
            Member code: {{ $data->member_code ?? '-' }} |
            Phone: {{ $data->primary_contact ?? '-' }}
        <p>    Type: {{ $fullcertificate ?? '-' }}</p>
        </div>

        <table>
            <thead>
            <tr>
                <th>REFERENCE</th>
                <th>DATE</th>
                <th>CERT NO</th>
                <th>SHARES</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>{{ $data->share_code ?? '-' }}</td>
                <td>{{ optional(\Carbon\Carbon::parse($data->purchased_at))->format('d M Y') }}</td>
                <td>{{ $data->id ?? '-' }}</td>
                <td>{{ number_format($data->share_no ?? 0) }}</td>
            </tr>
            </tbody>
        </table>

        <div class="footer">
            <div class="seal">
                OFFICIAL<br>SEAL
            </div>

            <div class="signatures">
                <div class="sig">
                    <div class="sig-line">DIRECTOR</div>
                </div>
                <div class="sig">
                    <div class="sig-line">SECRETARY</div>
                </div>
            </div>
        </div>

    </div>
</div>
</body>
</html>