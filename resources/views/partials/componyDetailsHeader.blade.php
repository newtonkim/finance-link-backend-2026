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
@endphp


<table width="100%" cellspacing="0" cellpadding="0" style="border-bottom:1px solid #ddd; padding-bottom:15px; margin-bottom:20px; font-family: Arial, sans-serif;">
    <tr>
        
        <!-- Logo -->
        <td width="120" style="vertical-align:middle;">
            @if (!empty($logoDataUri))
                <img src="{{ $logoDataUri }}" alt="Company Logo"
                    style="max-height:80px; max-width:120px; object-fit:contain;">
            @else
                <table width="120" height="80" style="border:1px dashed #d1d5db; background:#f3f4f6; border-radius:6px;">
                    <tr>
                        <td align="center" valign="middle" style="font-size:10px; color:#9ca3af;">
                            {{ strtoupper(substr($saccoName, 0, 2)) }}
                        </td>
                    </tr>
                </table>
            @endif
        </td>

        <!-- Spacer -->
        <td width="10"></td>

        <!-- Company Details -->
        <td align="right" style="vertical-align:middle;">
            <div style="font-size:20px; font-weight:bold; color:#111827;">
                {{ $saccoName }}
            </div>

            @if (!empty($saccoTagline))
                <div style="font-size:12px; color:#6b7280; margin-top:4px; font-style:italic;">
                    {{ $saccoTagline }}
                </div>
            @endif

            <div style="font-size:11px; color:#9ca3af; margin-top:6px;">
                Generated on: {{ now()->format('d M Y, H:i') }}
            </div>
        </td>

    </tr>
</table>