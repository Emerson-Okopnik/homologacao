/// <reference lib="dom" />
import { expect, test, type Page } from '@playwright/test'

const login = {
  id: 'log-login', event: 'auth.login', actor: { id: 'user-1', name: 'Administrador' },
  subject_type: 'User', old_values: null, new_values: null, metadata: null, justification: null,
  ip_address: '172.19.0.1', correlation_id: '5a693222-95dd-4431-bfe1-3ae5f46e9c4b', created_at: '2026-10-13T22:43:00Z',
}
const update = {
  ...login, id: 'log-update', event: 'user.updated', created_at: '2026-10-14T13:15:00Z',
  old_values: { name: 'Operador', active: false }, new_values: { name: 'Operador de homologação', active: true },
  metadata: { reason: 'Atualização de cadastro' }, justification: 'Ativação do responsável pela homologação.',
}

async function mockStaff(page: Page) {
  await page.route('**/api/**', route => {
    const url = new URL(route.request().url())
    if (url.pathname === '/api/auth/me') return route.fulfill({ json: { data: {
      user: { id: 'user-1', name: 'Administrador', email: 'admin@example.com', active: true },
      tenant: { id: 'tenant-1', name: 'Empresa teste', slug: 'teste' },
      permissions: ['audit.view', 'clients.view', 'clients.manage'],
    } } })
    const logs = url.pathname !== '/api/audit-logs' ? [] : url.searchParams.get('event') === 'auth.login' ? [login] : [update, login]
    return route.fulfill({ json: { data: logs, meta: { current_page: 1, last_page: 1, per_page: 25, total: logs.length, from: 1, to: logs.length } } })
  })
}

async function expectNoOverflow(page: Page) {
  const width = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, viewport: window.innerWidth }))
  expect(width.scroll).toBeLessThanOrEqual(width.viewport)
}

for (const width of [1440, 375]) {
  for (const theme of ['light', 'dark'] as const) {
    test(`auditoria legível em ${theme}, ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 1000 })
      await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' })
      await mockStaff(page)
      await page.goto('/auditoria')
      await expect(page.locator('html')).toHaveAttribute('data-theme', theme)
      const events = page.getByRole('list', { name: 'Eventos de auditoria' })
      await events.getByRole('button', { name: /Usuário alterado/ }).click()
      const details = page.getByRole('region', { name: /Usuário alterado/ })
      await expect(details.getByRole('heading', { name: /Alterações registradas/ })).toBeVisible()
      await expect(details).toContainText('Operador de homologação')
      await expect(details).toContainText('Atualização de cadastro')
      await expect(details.getByLabel('Dados técnicos do evento')).toBeHidden()
      await expectNoOverflow(page)
      await page.screenshot({ path: `test-results/audit-${theme}-${width}.png`, fullPage: true })
      await events.getByRole('button', { name: /Acesso ao sistema/ }).click()
      await expect(page.getByRole('region', { name: /Acesso ao sistema/ })).toContainText('Evento de autenticação. Não há alterações de dados associadas.')
      await page.getByText('Ver dados técnicos', { exact: true }).click()
      const technical = page.getByLabel('Dados técnicos do evento')
      await expect(technical).toBeVisible()
      const contrast = await technical.evaluate(element => {
        const style = getComputedStyle(element)
        const luminance = (color: string) => {
          const parts = color.match(/[\d.]+/g)!.slice(0, 3).map(Number).map(value => {
            const channel = value / 255
            return channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4
          })
          return parts[0]! * .2126 + parts[1]! * .7152 + parts[2]! * .0722
        }
        const foreground = luminance(style.color)
        const background = luminance(style.backgroundColor)
        return (Math.max(foreground, background) + .05) / (Math.min(foreground, background) + .05)
      })
      expect(contrast).toBeGreaterThanOrEqual(4.5)
      await expectNoOverflow(page)
      await page.getByRole('combobox', { name: 'Evento', exact: true }).click()
      const request = page.waitForRequest(request => new URL(request.url()).searchParams.get('event_match') === 'exact')
      await page.getByRole('option', { name: 'Acesso ao sistema', exact: true }).click()
      await request
      await expect(events.getByRole('button')).toHaveCount(1)
    })
  }
}

test('salva a escolha do tema e a restaura após recarregar', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await mockStaff(page)
  await page.goto('/auditoria')
  await page.getByRole('button', { name: 'Ativar tema claro' }).click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
  await page.getByRole('button', { name: 'Ativar tema escuro' }).click()
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})

test('tema escuro no login e nos controles de um diálogo', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.route('**/api/auth/me', route => route.fulfill({ status: 401, json: { message: 'Não autenticado' } }))
  await page.goto('/login')
  await expect(page.getByRole('button', { name: 'Ativar tema claro' })).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await page.screenshot({ path: 'test-results/login-dark.png', fullPage: true })
  await page.unroute('**/api/auth/me')
  await mockStaff(page)
  await page.goto('/clientes')
  await page.getByRole('button', { name: 'Novo cliente', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('combobox', { name: 'Tipo de pessoa' }).click()
  const menu = dialog.getByRole('listbox')
  await expect(menu).toBeVisible()
  expect(await menu.evaluate(element => getComputedStyle(element).backgroundColor)).toBe('rgb(21, 31, 48)')
  await expectNoOverflow(page)
})

test('tema escuro no portal em celular de 320px', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 900 })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.route('**/api/**', route => {
    const path = new URL(route.request().url()).pathname
    const data = path === '/api/auth/me'
      ? { user: { id: 'user-1', name: 'Cliente teste', email: 'cliente@example.com', active: true }, tenant: null, permissions: ['portal.access'], client: { id: 'client-1', name: 'Titular teste' } }
      : path === '/api/portal/summary' ? { requests_open: 0, needs_info: 0, converted: 0, units: 0 } : []
    return route.fulfill({ json: { data } })
  })
  await page.goto('/portal')
  await expect(page.getByRole('button', { name: 'Ativar tema claro' })).toBeVisible()
  await expectNoOverflow(page)
  await page.screenshot({ path: 'test-results/portal-dark-320.png', fullPage: true })
})
