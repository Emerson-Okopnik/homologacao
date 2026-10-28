import { expect, test } from '@playwright/test'

const statuses = [
  ['rascunho', 'Rascunho'], ['em_preparacao', 'Em preparação'],
  ['pronto_para_envio', 'Pronto para envio'], ['enviado', 'Enviado'],
  ['em_analise', 'Em análise'], ['pendencia_distribuidora', 'Pendência da distribuidora'],
  ['aprovado', 'Parecer aprovado'], ['vistoria_solicitada', 'Vistoria solicitada'], ['reprovado', 'Reprovado'],
].map(([value, label]) => ({ value, label, stage_type: 'em_preparacao' }))

test.beforeEach(async ({ page }) => {
  await page.route('**/api/**', route => {
    const path = new URL(route.request().url()).pathname
    if (path === '/api/auth/me') return route.fulfill({ json: { data: {
      user: { id: 'user-1', name: 'Pessoa teste', email: 'teste@example.com', active: true },
      tenant: { id: 'tenant-1', name: 'Empresa teste', slug: 'teste' },
      permissions: ['homologations.view', 'clients.view', 'clients.manage'],
    } } })
    if (path === '/api/process-statuses') return route.fulfill({ json: { data: statuses } })
    return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null } } })
  })
})

for (const width of [1440, 375]) {
  test(`select de situação com menu próprio em ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.goto('/processos')
    const select = page.getByRole('combobox', { name: 'Situação', exact: true })
    await expect(select).toContainText('Todas as situações')
    await expect(page.getByRole('navigation', { name: 'Paginação', exact: true })).toBeVisible()
    await select.click()
    const menu = page.getByRole('listbox', { name: 'Situação', exact: true })
    await expect(menu).toBeVisible()
    await expect(menu.getByRole('option')).toHaveCount(10)
    await expect(menu.getByRole('option', { name: 'Todas as situações' })).toHaveAttribute('aria-selected', 'true')
    const bounds = await menu.boundingBox()
    expect(bounds!.x).toBeGreaterThanOrEqual(0)
    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width)
    expect(bounds!.y + bounds!.height).toBeLessThanOrEqual(900)
    await page.screenshot({ path: `test-results/select-${width}.png` })
    const request = page.waitForRequest(request => new URL(request.url()).searchParams.get('status') === 'em_analise')
    await menu.getByRole('option', { name: 'Em análise', exact: true }).click()
    await request
    await expect(select).toContainText('Em análise')
    await expect(menu).toBeHidden()
    await expect(select).toBeFocused()
    await select.press('Home')
    await select.press('Enter')
    await expect(select).toContainText('Todas as situações')
    await select.click()
    await page.getByRole('heading', { name: 'Homologações', exact: true }).click()
    await expect(menu).toBeHidden()
  })
}

test('menu permanece acessível dentro do diálogo e Escape fecha somente o select', async ({ page }) => {
  await page.goto('/clientes')
  await page.getByRole('button', { name: 'Novo cliente', exact: true }).click()
  const dialog = page.getByRole('dialog')
  const select = dialog.getByRole('combobox', { name: 'Tipo de pessoa', exact: true })
  await select.click()
  const menu = dialog.getByRole('listbox', { name: 'Tipo de pessoa', exact: true })
  await expect(menu).toBeVisible()
  await select.press('Escape')
  await expect(menu).toBeHidden()
  await expect(dialog).toBeVisible()
  await select.click()
  await menu.getByRole('option', { name: 'Pessoa jurídica', exact: true }).click()
  await expect(dialog.getByRole('textbox', { name: 'CNPJ', exact: true })).toBeVisible()
  await expect(select).toHaveText('Pessoa jurídica')
  await expect(select).toBeFocused()
})
