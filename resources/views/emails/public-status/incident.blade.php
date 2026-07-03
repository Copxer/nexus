<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $alertTitle }}</title>
</head>
<body style="margin:0;padding:0;background:#0f172a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#e2e8f0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#1e293b;border:1px solid rgba(148,163,184,0.24);border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="padding:20px 24px;background:{{ $event === 'resolved' ? '#065f46' : ($alertSeverity === 'critical' ? '#7f1d1d' : ($alertSeverity === 'warning' ? '#78350f' : '#155e75')) }};color:#f8fafc;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;font-weight:600;">
                            @if ($event === 'resolved')
                                {{ $projectName }} · Incident resolved
                            @else
                                {{ $projectName }} · Incident open · {{ ucfirst($alertSeverity) }}
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 12px;font-size:20px;font-weight:600;color:#f8fafc;">{{ $alertTitle }}</p>
                            @if (! empty($alertDescription))
                                <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#cbd5e1;">{{ $alertDescription }}</p>
                            @endif
                            <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 20px;font-size:13px;color:#94a3b8;">
                                <tr>
                                    <td style="padding:4px 0;width:120px;">Started</td>
                                    <td style="padding:4px 0;color:#e2e8f0;">{{ optional($triggeredAt)->format('Y-m-d H:i:s T') ?? '—' }}</td>
                                </tr>
                                @if ($event === 'resolved')
                                    <tr>
                                        <td style="padding:4px 0;">Resolved</td>
                                        <td style="padding:4px 0;color:#e2e8f0;">{{ optional($resolvedAt)->format('Y-m-d H:i:s T') ?? '—' }}</td>
                                    </tr>
                                @endif
                            </table>
                            <a href="{{ $statusUrl }}" style="display:inline-block;padding:10px 18px;background:#22d3ee;color:#0f172a;font-weight:600;text-decoration:none;border-radius:8px;font-size:14px;">View status page</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;background:#0f172a;color:#64748b;font-size:12px;">
                            You're subscribed to incident updates for {{ $projectName }}.
                            <a href="{{ $unsubscribeUrl }}" style="color:#22d3ee;text-decoration:none;">Unsubscribe</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
