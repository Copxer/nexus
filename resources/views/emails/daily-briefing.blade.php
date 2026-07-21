<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $payload->title() }}</title>
</head>
<body style="margin:0;padding:0;background:#0f172a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,sans-serif;color:#e2e8f0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#1e293b;border:1px solid rgba(148,163,184,0.24);border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="padding:20px 24px;background:#155e75;color:#f8fafc;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;font-weight:600;">
                            Daily Briefing · {{ $payload->briefingDate }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#cbd5e1;white-space:pre-line;">{{ $payload->summary }}</p>

                            @if ($payload->highlights !== [])
                                <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#f8fafc;">Highlights</p>
                                <ul style="margin:0 0 20px;padding-left:20px;font-size:14px;line-height:1.6;color:#cbd5e1;">
                                    @foreach ($payload->highlights as $highlight)
                                        <li>{{ $highlight }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            @if ($payload->risks !== [])
                                <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#f8fafc;">Risks</p>
                                <ul style="margin:0 0 20px;padding-left:20px;font-size:14px;line-height:1.6;color:#cbd5e1;">
                                    @foreach ($payload->risks as $risk)
                                        <li>{{ $risk }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            <a href="{{ $payload->link }}" style="display:inline-block;padding:10px 18px;background:#22d3ee;color:#0f172a;font-weight:600;text-decoration:none;border-radius:8px;font-size:14px;">View in Nexus</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;background:#0f172a;color:#64748b;font-size:12px;">
                            You're receiving this because daily briefings are enabled in your Nexus settings.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
