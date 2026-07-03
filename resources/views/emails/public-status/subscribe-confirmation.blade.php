<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Confirm your subscription</title>
</head>
<body style="margin:0;padding:0;background:#0f172a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#e2e8f0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#1e293b;border:1px solid rgba(148,163,184,0.24);border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 12px;font-size:20px;font-weight:600;color:#f8fafc;">
                                Confirm your subscription
                            </p>
                            <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#cbd5e1;">
                                You (or someone using this address) asked to receive incident notifications for
                                <strong>{{ $projectName }}</strong>. Click below to confirm.
                            </p>
                            <a href="{{ $confirmUrl }}" style="display:inline-block;padding:10px 18px;background:#22d3ee;color:#0f172a;font-weight:600;text-decoration:none;border-radius:8px;font-size:14px;">
                                Confirm subscription
                            </a>
                            <p style="margin:24px 0 0;font-size:12px;color:#94a3b8;">
                                If you didn't request this, ignore the email — no subscription will be created.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px;background:#0f172a;color:#64748b;font-size:12px;">
                            Status page: <a href="{{ $statusUrl }}" style="color:#22d3ee;text-decoration:none;">{{ $statusUrl }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
