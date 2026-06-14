<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $document['platform_name'] }} Invoice</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;max-width:600px;width:100%;">
                    <tr>
                        <td style="background:#052659;padding:24px 32px;color:#ffffff;">
                            <div style="font-size:20px;font-weight:bold;">{{ $document['platform_name'] }}</div>
                            <div style="font-size:13px;opacity:0.8;margin-top:2px;">{{ $document['platform_tagline'] }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 8px;font-size:20px;color:#052659;">Invoice {{ $document['invoice']->invoice_number }}</h1>
                            <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#374151;">
                                Hello {{ $document['tenant_name'] }},<br>
                                Please find attached your license renewal invoice. A summary is shown below.
                            </p>

                            @if($note)
                                <p style="margin:0 0 20px;padding:12px 16px;background:#f1f5fb;border-radius:8px;font-size:14px;color:#334155;">
                                    {{ $note }}
                                </p>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;">
                                <tr>
                                    <td style="padding:12px 16px;font-size:13px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Plan</td>
                                    <td style="padding:12px 16px;font-size:13px;font-weight:bold;text-align:right;border-bottom:1px solid #f3f4f6;">{{ $document['plan_name'] }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px;font-size:13px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Billing Cycle</td>
                                    <td style="padding:12px 16px;font-size:13px;font-weight:bold;text-align:right;border-bottom:1px solid #f3f4f6;">{{ $document['billing_cycle'] }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px;font-size:13px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Status</td>
                                    <td style="padding:12px 16px;font-size:13px;font-weight:bold;text-align:right;border-bottom:1px solid #f3f4f6;">{{ $document['status_label'] }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;font-size:15px;color:#052659;font-weight:bold;">Total</td>
                                    <td style="padding:14px 16px;font-size:15px;color:#052659;font-weight:bold;text-align:right;">
                                        {{ $document['is_converted'] ? $document['charge_total'] : $document['total'] }}
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#6b7280;">
                                The full invoice is attached as a PDF. If you have any questions about this invoice,
                                simply reply to this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px;background:#f9fafb;font-size:12px;color:#9ca3af;text-align:center;">
                            This is a system-generated email from {{ $document['platform_name'] }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
