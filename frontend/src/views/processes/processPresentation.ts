import type { HomologationProcess } from '@/types/api'

export type ProcessSection = 'summary' | 'technical' | 'distributor' | 'history'

export function isProcessClosed(process: HomologationProcess) {
  // A reprovação permite corrigir o projeto e retomar a preparação.
  return ['conectado', 'cancelado'].includes(process.status)
}

export function processGuidance(process: HomologationProcess) {
  switch (process.status) {
    case 'rascunho':
    case 'em_preparacao':
      return { title: 'Preparar o projeto', description: 'Complete os dados técnicos e envie os documentos exigidos antes de solicitar a análise.', responsibility: 'Equipe técnica', section: 'technical' as const, action: 'Ver documentos e dados técnicos' }
    case 'pronto_para_envio':
      return { title: 'Enviar à distribuidora', description: 'Prepare o dossiê e registre o protocolo com o comprovante do envio pelo portal da distribuidora.', responsibility: 'Equipe de homologação', section: 'distributor' as const, action: 'Ver envio à distribuidora' }
    case 'enviado':
    case 'em_analise':
      return { title: 'Aguardar a análise', description: 'Acompanhe o retorno da distribuidora. Se houver uma exigência, registre o que precisa ser corrigido.', responsibility: 'Distribuidora', section: 'distributor' as const, action: 'Ver acompanhamento' }
    case 'pendencia_distribuidora':
      return { title: 'Atender às exigências', description: 'Confira as pendências, corrija o que foi solicitado e reúna os documentos para o reenvio.', responsibility: 'Equipe técnica e homologação', section: 'technical' as const, action: 'Ver documentos para correção' }
    case 'reprovado':
      return { title: 'Revisar o projeto', description: 'Confira o motivo da reprovação no histórico e revise o projeto antes de retomar a preparação.', responsibility: 'Equipe técnica', section: 'history' as const, action: 'Ver motivo e histórico' }
    case 'aprovado':
      if (process.actions?.request_inspection?.available) {
        return { title: 'Registrar a vistoria solicitada', description: 'O envio da solicitação foi confirmado. Registre a vistoria para acompanhar o agendamento e o resultado.', responsibility: 'Equipe de homologação', section: 'distributor' as const, action: 'Ver solicitação de vistoria' }
      }
      if (process.stage === 'INSPECTION' && process.execution && process.phase_checklist?.blocking.length === 0) {
        return { title: 'Solicitar a vistoria', description: 'Prepare o dossiê da vistoria e registre o protocolo e o comprovante da solicitação enviada à distribuidora.', responsibility: 'Equipe de homologação', section: 'distributor' as const, action: 'Ver envio da vistoria' }
      }
      return { title: 'Preparar a vistoria', description: 'Confira a execução da instalação, a ART/TRT e a situação das obras de rede antes de solicitar a vistoria.', responsibility: 'Equipe técnica e homologação', section: 'technical' as const, action: 'Ver requisitos da vistoria' }
    case 'vistoria_solicitada':
      return process.stage === 'CONNECTION'
        ? { title: 'Acompanhar a conexão', description: 'Registre as evidências da conexão e confira os requisitos antes de concluir o processo.', responsibility: 'Distribuidora e homologação', section: 'technical' as const, action: 'Ver requisitos da conexão' }
        : { title: 'Acompanhar a vistoria', description: 'Confira o agendamento e registre o resultado e o relatório quando a distribuidora realizar a vistoria.', responsibility: 'Distribuidora', section: 'distributor' as const, action: 'Ver vistoria' }
    case 'conectado':
      return { title: 'Sistema conectado', description: 'O processo foi concluído. Os documentos e registros continuam disponíveis para consulta.', responsibility: 'Processo concluído', section: 'history' as const, action: 'Consultar histórico' }
    case 'cancelado':
      return { title: 'Processo cancelado', description: 'Consulte o histórico para verificar o motivo do cancelamento.', responsibility: 'Processo encerrado', section: 'history' as const, action: 'Consultar histórico' }
  }
}

export function processDeadline(process: HomologationProcess) {
  if (isProcessClosed(process)) return null
  if (process.open_deadline) return { date: process.open_deadline.due_at, label: process.open_deadline.label, overdue: process.open_deadline.overdue }
  if (!process.due_date) return null
  const now = new Date()
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  return { date: process.due_date, label: 'Prazo interno', overdue: process.due_date < today }
}
