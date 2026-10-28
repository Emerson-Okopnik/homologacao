# VERIGRID — Interfaces de homologação

Arquivo principal: [VERIGRID-Homologacao.figcraft](VERIGRID-Homologacao.figcraft).
Galeria: [preview.html](preview.html).

Criado no FigCraft com camadas editáveis, logotipo original, tipografia Sora, cores e planos angulares da identidade fornecida. O escopo foi baseado no frontend e backend do repositório.

## Conteúdo

- 22 telas: 19 desktop e 3 mobile.
- 14 diálogos e estados de feedback.
- Fundamentos, 7 componentes, 11 estilos e 11 variáveis.
- 3 fluxos de protótipo: Operação interna, Portal do cliente e Portal mobile.
- 222 interações de navegação e diálogos.
- 37 prévias PNG em `previews/`.

## Organização no FigCraft

1. **00 · Fundamentos e componentes:** marca, paleta, Sora, botões, campo, documento e alerta.
2. **01 · Operação:** acesso, dashboard, Kanban, homologação, revisão documental, projeto, distribuidora, vistoria, solicitações, cliente e configurações.
3. **02 · Portal do cliente:** início, solicitação em quatro etapas, acompanhamento e documentos.
4. **03 · Mobile:** início, acompanhamento e documentos.
5. **04 · Diálogos e estados:** upload, revisão, requisitos pendentes, criação, vistoria, recuperação de acesso e confirmações.

## Como revisar

Abra o arquivo nativo no FigCraft. No painel de protótipo, use os pontos de partida **01 · Operação interna**, **02 · Portal do cliente** e **03 · Portal mobile**. Os fluxos principais possuem links; formulários e valores são exemplos visuais, sem integração com a API.

A galeria HTML reúne todas as telas e estados. As imagens abrem no tamanho original. A fonte Sora e sua licença estão em `assets/`, junto às referências de identidade fornecidas.

## Decisões de produto

- Seis fases: Preparação, Análise da distribuidora, Correção, Execução, Vistoria e Conexão.
- Fase e situação aparecem separadas, com próximo passo e responsável.
- Envios à distribuidora são assistidos, com registro do protocolo e comprovante.
- Parecer aprovado inicia a execução; conexão conclui a homologação.
- O portal distingue os documentos do titular e as atividades técnicas da equipe.
- RTs podem ser definidos em momentos diferentes; o formulário indica quando são necessários.
- Os dados pessoais, códigos, potências, datas e arquivos são demonstrativos.

## Verificação

Todas as telas foram renderizadas. A revisão incluiu legibilidade, limites dos frames, fontes e destinos de navegação. A verificação estrutural final encontrou **0 elementos de texto/superfície fora dos limites** e **0 links para destinos ausentes**.

