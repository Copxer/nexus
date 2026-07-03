<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $payload->title }}</title>
</head>
<body style="margin:0;padding:0;background:#0f172a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,sans-serif;color:#e2e8f0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#1e293b;border:1px solid rgba(148,163,184,0.24);border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="padding:20px 24px;background:{{ $payload->severity === 'critical' ? '#7f1d1d' : ($payload->severity === 'warning' ? '#78350f' : '#155e75') }};color:#f8fafc;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;font-weight:600;">
                            @if ($payload->event === 'alert.resolved')
                                Resolved · {{ ucfirst($payload->severity) }}
                            @else
                                Alert · {{ ucfirst($payload->severity) }}
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 12px;font-size:20px;font-weight:600;color:#f8fafc;">{{ $payload->title }}</p>
                            @if (! empty($payload->message))
                                <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#cbd5e1;">{{ $payload->message }}</p>
                            @endif
                            <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 20px;font-size:13px;color:#94a3b8;">
                                <tr>
                                    <td style="padding:4px 0;width:96px;">Source</td>
                                    <td style="padding:4px 0;color:#e2e8f0;">{{ ucfirst($payload->source) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;">Type</td>
                                    <td style="padding:4px 0;color:#e2e8f0;">{{ $payload->type }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;">Triggered</td>
                                    <td style="padding:4px 0;color:#e2e8f0;">{{ $payload->triggeredAt->format('Y-m-d H:i:s T') }}</td>
                                </tr>
                            </table>
                            <a href="{{ $payload->link }}" style="display:inline-block;padding:10px 18px;background:#22d3ee;color:#0f172a;font-weight:600;text-decoration:none;border-radius:8px;font-size:14px;">View in Nexus</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;background:#0f172a;color:#64748b;font-size:12px;">
                            You're receiving this because a notification channel in your Nexus settings is subscribed to this alert. Adjust in Settings → Notifications.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
