<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Redefina sua senha</title>
    <style>
        @media only screen and (max-width: 620px) {
            .email-shell { width: 100% !important; }
            .email-card { border-radius: 0 !important; }
            .content-cell { padding: 32px 24px !important; }
            .header-cell { padding: 24px !important; }
            .footer-cell { padding: 24px !important; }
            .button-link { display: block !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f7fb; color: #0f172a; font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: transparent;">
        Use este link para criar uma nova senha. Ele expira em {{ $expiresInMinutes }} minutos.
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; background-color: #f4f7fb;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <table role="presentation" class="email-shell" width="600" cellspacing="0" cellpadding="0" border="0" style="width: 600px; max-width: 600px;">
                    <tr>
                        <td class="email-card" style="overflow: hidden; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td class="header-cell" style="padding: 28px 40px; background-color: #0d1420;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td width="48" valign="middle">
                                                    <div style="width: 40px; height: 40px; line-height: 40px; border-radius: 12px; background-color: #2563eb; color: #ffffff; font-size: 22px; font-weight: 700; text-align: center;">H</div>
                                                </td>
                                                <td valign="middle" style="padding-left: 12px; color: #ffffff;">
                                                    <div style="font-size: 18px; line-height: 24px; font-weight: 700; letter-spacing: -0.2px;">Homologa Solar</div>
                                                    <div style="margin-top: 2px; color: #8b98ad; font-size: 12px; line-height: 18px;">Gestão de homologação fotovoltaica</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="content-cell" style="padding: 44px 40px 40px;">
                                        <div style="display: inline-block; margin-bottom: 20px; padding: 6px 10px; border-radius: 999px; background-color: #e8efff; color: #1d4ed8; font-size: 12px; line-height: 18px; font-weight: 700; letter-spacing: 0.4px; text-transform: uppercase;">Segurança da conta</div>

                                        <h1 style="margin: 0; color: #0f172a; font-size: 28px; line-height: 36px; font-weight: 750; letter-spacing: -0.6px;">Redefina sua senha</h1>

                                        <p style="margin: 20px 0 0; color: #334155; font-size: 16px; line-height: 26px;">
                                            Olá@if ($userName !== ''), {{ $userName }}@endif.
                                        </p>

                                        <p style="margin: 12px 0 0; color: #475569; font-size: 15px; line-height: 25px;">
                                            Recebemos uma solicitação para redefinir a senha da conta <strong style="color: #0f172a;">{{ $userEmail }}</strong>. Clique no botão abaixo para escolher uma nova senha.
                                        </p>

                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top: 28px;">
                                            <tr>
                                                <td style="border-radius: 10px; background-color: #2563eb;">
                                                    <a class="button-link" href="{{ $resetUrl }}" target="_blank" rel="noopener" style="display: inline-block; padding: 14px 24px; border: 1px solid #2563eb; border-radius: 10px; color: #ffffff; font-size: 15px; line-height: 20px; font-weight: 700; text-decoration: none;">Criar nova senha</a>
                                                </td>
                                            </tr>
                                        </table>

                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: 28px;">
                                            <tr>
                                                <td style="padding: 16px 18px; border: 1px solid #bfdbfe; border-radius: 10px; background-color: #eff6ff;">
                                                    <p style="margin: 0; color: #1e3a8a; font-size: 14px; line-height: 22px;">
                                                        <strong>Este link expira em {{ $expiresInMinutes }} minutos.</strong><br>
                                                        Por segurança, ele só pode ser usado para esta solicitação.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style="margin: 28px 0 0; color: #64748b; font-size: 13px; line-height: 21px;">
                                            Se o botão não funcionar, copie e cole este endereço no navegador:
                                        </p>
                                        <p style="margin: 8px 0 0; word-break: break-all; font-size: 12px; line-height: 19px;">
                                            <a href="{{ $resetUrl }}" target="_blank" rel="noopener" style="color: #2563eb; text-decoration: underline;">{{ $resetUrl }}</a>
                                        </p>

                                        <hr style="margin: 32px 0 24px; border: 0; border-top: 1px solid #e2e8f0;">

                                        <p style="margin: 0; color: #64748b; font-size: 13px; line-height: 21px;">
                                            Não solicitou esta alteração? Ignore este e-mail. Sua senha atual continuará válida. Nunca compartilhe este link com outras pessoas.
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="footer-cell" style="padding: 24px 40px; border-top: 1px solid #e2e8f0; background-color: #f8fafc; text-align: center;">
                                        <p style="margin: 0; color: #64748b; font-size: 12px; line-height: 19px;">Mensagem automática enviada pelo Homologa Solar.</p>
                                        <p style="margin: 4px 0 0; color: #94a3b8; font-size: 11px; line-height: 18px;">© {{ $year }} Homologa Solar. Todos os direitos reservados.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
