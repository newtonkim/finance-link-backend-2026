<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 32px 36px; }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #1f2937;
            font-size: 12px;
            line-height: 1.45;
            margin: 0;
        }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .navy { color: #052659; }
        h1, h2, h3 { margin: 0; }

        /* ── Header ── */
        .header { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .brand-name { font-size: 20px; font-weight: bold; color: #052659; }
        .brand-tag { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .logo { max-height: 52px; max-width: 180px; }
        .doc-title { font-size: 26px; font-weight: bold; color: #052659; letter-spacing: 1px; }
        .doc-meta { font-size: 11px; color: #6b7280; margin-top: 6px; }
        .doc-meta b { color: #1f2937; }
        .status-pill {
            display: inline-block; padding: 4px 12px; border-radius: 12px;
            font-size: 11px; font-weight: bold; margin-top: 8px;
            color: {{ $status_color }}; background: {{ $status_bg }};
        }

        .rule { border: none; border-top: 2px solid #052659; margin: 16px 0; }

        /* ── Parties ── */
        .parties { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .parties td { vertical-align: top; width: 50%; padding-right: 16px; }
        .label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 4px; }
        .party-name { font-size: 14px; font-weight: bold; color: #111827; }

        /* ── Items table ── */
        .items { width: 100%; border-collapse: collapse; margin-top: 18px; }
        .items th {
            background: #052659; color: #ffffff; font-size: 10px; text-transform: uppercase;
            letter-spacing: 0.5px; text-align: left; padding: 9px 12px;
        }
        .items th.r, .items td.r { text-align: right; }
        .items td { padding: 11px 12px; border-bottom: 1px solid #e5e7eb; }
        .items .desc { font-weight: bold; color: #111827; }
        .items .sub { font-size: 10px; color: #6b7280; margin-top: 2px; }

        /* ── Totals ── */
        .totals { width: 46%; border-collapse: collapse; float: right; margin-top: 12px; }
        .totals td { padding: 6px 12px; font-size: 12px; }
        .totals .tlabel { color: #6b7280; }
        .totals .tval { text-align: right; font-weight: bold; color: #111827; }
        .totals .grand td { border-top: 2px solid #052659; padding-top: 10px; font-size: 14px; }
        .totals .grand .tlabel { color: #052659; font-weight: bold; }
        .totals .grand .tval { color: #052659; }
        .totals .charge td { background: #f1f5fb; }

        .clear { clear: both; }

        /* ── Info cards ── */
        .info { width: 100%; border-collapse: collapse; margin-top: 26px; }
        .info td { vertical-align: top; width: 50%; padding-right: 14px; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; }
        .card h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #052659; margin-bottom: 8px; }
        .kv { width: 100%; border-collapse: collapse; }
        .kv td { padding: 3px 0; font-size: 11px; }
        .kv td.k { color: #6b7280; }
        .kv td.v { text-align: right; font-weight: bold; color: #111827; }

        .fx-note {
            margin-top: 14px; padding: 10px 14px; border-radius: 8px;
            background: #f1f5fb; border: 1px solid #dce5f1; font-size: 11px; color: #334155;
        }

        .footer { margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 12px; font-size: 10px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>

    {{-- Header --}}
    <table class="header">
        <tr>
            <td>
                @if($logo_path)
                    <img class="logo" src="{{ $logo_path }}" alt="logo">
                @else
                    <div class="brand-name">{{ $platform_name }}</div>
                    <div class="brand-tag">{{ $platform_tagline }}</div>
                @endif
            </td>
            <td class="right">
                <div class="doc-title">INVOICE</div>
                <div class="doc-meta"># <b>{{ $invoice->invoice_number }}</b></div>
                <div class="doc-meta">Issued: <b>{{ $issued_at }}</b></div>
                <div class="doc-meta">Due: <b>{{ $due_at }}</b></div>
                <div class="status-pill">{{ $status_label }}</div>
            </td>
        </tr>
    </table>

    <hr class="rule">

    {{-- Parties --}}
    <table class="parties">
        <tr>
            <td>
                <div class="label">Billed To</div>
                <div class="party-name">{{ $tenant_name }}</div>
                @if($tenant_code)<div class="muted">{{ $tenant_code }}</div>@endif
            </td>
            <td>
                <div class="label">From</div>
                <div class="party-name">{{ $platform_name }}</div>
                <div class="muted">{{ $platform_tagline }}</div>
            </td>
        </tr>
    </table>

    {{-- Line items --}}
    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th>Billing Cycle</th>
                <th class="r">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div class="desc">{{ $plan_name }} — License Renewal</div>
                    <div class="sub">Coverage: {{ $renewal_start }} → {{ $renewal_end }}</div>
                </td>
                <td>{{ $billing_cycle }}</td>
                <td class="r">{{ $subtotal }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Totals --}}
    <table class="totals">
        <tr>
            <td class="tlabel">Subtotal</td>
            <td class="tval">{{ $subtotal }}</td>
        </tr>
        <tr>
            <td class="tlabel">Service Fee</td>
            <td class="tval">{{ $service_fee }}</td>
        </tr>
        <tr class="grand">
            <td class="tlabel">Total ({{ $base_currency }})</td>
            <td class="tval">{{ $total }}</td>
        </tr>
        @if($is_converted)
        <tr class="charge">
            <td class="tlabel">Amount Charged ({{ $charge_currency }})</td>
            <td class="tval">{{ $charge_total }}</td>
        </tr>
        @endif
    </table>
    <div class="clear"></div>

    @if($is_converted)
        <div class="fx-note">
            <b>Currency conversion:</b> This invoice is settled in {{ $base_currency }}.
            The customer was charged in {{ $charge_currency }} at a snapshot rate of
            <b>{{ $fx_rate_label }}</b>, fixed at the time of payment.
        </div>
    @endif

    {{-- Info cards --}}
    <table class="info">
        <tr>
            <td>
                <div class="card">
                    <h3>Payment Details</h3>
                    <table class="kv">
                        <tr><td class="k">Method</td><td class="v">{{ $payment_method_label }}</td></tr>
                        @if($payment && $payment->provider)
                            <tr><td class="k">Provider</td><td class="v">{{ strtoupper($payment->provider) }}</td></tr>
                        @endif
                        @if($payment && $payment->provider_reference)
                            <tr><td class="k">Reference</td><td class="v">{{ $payment->provider_reference }}</td></tr>
                        @endif
                        @if($payment && $payment->phone_number)
                            <tr><td class="k">Phone</td><td class="v">{{ $payment->phone_number }}</td></tr>
                        @endif
                        @if($payment && $payment->account_name)
                            <tr><td class="k">Account Name</td><td class="v">{{ $payment->account_name }}</td></tr>
                        @endif
                        @if($paid_at)
                            <tr><td class="k">Paid At</td><td class="v">{{ $paid_at }}</td></tr>
                        @endif
                    </table>
                </div>
            </td>
            <td>
                <div class="card">
                    <h3>Subscription</h3>
                    <table class="kv">
                        <tr><td class="k">Plan</td><td class="v">{{ $plan_name }}</td></tr>
                        <tr><td class="k">Billing Cycle</td><td class="v">{{ $billing_cycle }}</td></tr>
                        <tr><td class="k">Renewal Start</td><td class="v">{{ $renewal_start }}</td></tr>
                        <tr><td class="k">Renewal End</td><td class="v">{{ $renewal_end }}</td></tr>
                        <tr><td class="k">Settlement Currency</td><td class="v">{{ $base_currency }}</td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        This is a system-generated invoice from {{ $platform_name }} · Generated {{ $generated_at }}.<br>
        Thank you for your business.
    </div>

</body>
</html>
