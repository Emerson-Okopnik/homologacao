<?php

namespace App\Console\Commands;

use App\Notifications\MailTransportTestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class TestMail extends Command
{
    protected $signature = 'mail:test {recipient? : E-mail que receberá o teste} {--send : Enfileira um e-mail real de teste}';

    protected $description = 'Verifica a configuração de e-mail; somente --send enfileira uma mensagem';

    public function handle(): int
    {
        $recipient = trim((string) $this->argument('recipient'));
        if ($this->option('send') && ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error('Informe um endereço de e-mail válido para o destinatário.');

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        try {
            // Construir o transporte não conecta nem envia mensagens.
            Mail::mailer($mailer)->getSymfonyTransport();
        } catch (Throwable) {
            $this->error('Configuração de e-mail inválida. Revise MAIL_* sem expor as credenciais.');

            return self::FAILURE;
        }

        $this->table(['Configuração', 'Valor'], [
            ['Transporte', $mailer],
            ['Servidor', config("mail.mailers.{$mailer}.host", 'não se aplica')],
            ['Porta', config("mail.mailers.{$mailer}.port", 'não se aplica')],
            ['TLS obrigatório', config("mail.mailers.{$mailer}.require_tls") ? 'sim' : 'não'],
            ['Remetente', config('mail.from.address')],
            ['Conexão de fila', config('queue.default')],
            ['Fila de e-mails', config('mail.queue')],
        ]);

        if (! $this->option('send')) {
            $this->info('Configuração carregada. Nenhuma conexão SMTP ou envio foi realizado. Use --send para testar a entrega.');

            return self::SUCCESS;
        }

        try {
            Notification::route('mail', $recipient)->notify(new MailTransportTestNotification);
        } catch (Throwable) {
            $this->error('Não foi possível enfileirar o teste. Verifique a fila e os registros do worker.');

            return self::FAILURE;
        }

        $this->info('Teste solicitado. Verifique o worker, a caixa de entrada e a pasta de spam do destinatário.');

        return self::SUCCESS;
    }
}
