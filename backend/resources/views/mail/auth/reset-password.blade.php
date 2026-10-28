<x-mail::message>
# Olá, {{ $name }}!

Recebemos uma solicitação para redefinir a senha da sua conta em {{ config('app.name') }}.

<x-mail::button :url="$resetUrl">
Redefinir minha senha
</x-mail::button>

Este link pode ser usado uma única vez e é válido por até {{ $minutes }} minutos a partir da geração do link. Se você pediu mais de um link, use o mais recente.

Se você não fez esta solicitação, pode ignorar este e-mail. Sua senha continua a mesma.

Atenciosamente,<br>
Equipe {{ config('app.name') }}

<x-slot:subcopy>
Se não conseguir usar o botão, copie e cole este endereço no navegador:
[{{ $resetUrl }}]({{ $resetUrl }})
</x-slot:subcopy>
</x-mail::message>
