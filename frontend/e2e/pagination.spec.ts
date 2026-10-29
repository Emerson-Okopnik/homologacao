import { expect, test } from '@playwright/test'

for (const width of [1440, 375, 320]) {
  test(`pagina a listagem sem transbordar os controles em ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.route('**/api/auth/me', route => route.fulfill({ json: {
      data: {
        user: { id: 'user-1', name: 'Pessoa teste', email: 'teste@example.com', active: true },
        tenant: { id: 'tenant-1', name: 'Empresa teste', slug: 'teste' },
        permissions: ['clients.view'],
      },
    } }))
    await page.route('**/api/clients?*', route => {
      const query = new URL(route.request().url()).searchParams
      const currentPage = Number(query.get('page') ?? 1)
      const size = Number(query.get('per_page') ?? 20)
      const from = (currentPage - 1) * size + 1
      const to = Math.min(currentPage * size, 123)
      return route.fulfill({ json: {
        data: Array.from({ length: to - from + 1 }, (_, index) => ({
          id: `client-${from + index}`, name: `Cliente ${from + index}`, type: 'PF',
          document: '52998224725', status: 'active', email: 'cliente@example.com',
        })),
        meta: { current_page: currentPage, last_page: Math.ceil(123 / size), per_page: size, total: 123, from, to },
      } })
    })
    await page.goto('/clientes')
    const pagination = page.getByRole('navigation', { name: 'Paginação', exact: true })
    await expect(pagination).toContainText('Exibindo 1–20 de 123 registros')
    await pagination.getByRole('button', { name: 'Próxima página', exact: true }).click()
    await expect(pagination).toContainText('Exibindo 21–40 de 123 registros')
    await pagination.getByRole('combobox', { name: 'Registros por página' }).click()
    await page.getByRole('option', { name: '50', exact: true }).click()
    await expect(pagination).toContainText('Exibindo 1–50 de 123 registros')
    if (width >= 640) {
      await pagination.getByRole('button', { name: 'Página 3', exact: true }).click()
      await expect(pagination).toContainText('Exibindo 101–123 de 123 registros')
      await expect(pagination.getByRole('button', { name: 'Próxima página', exact: true })).toBeDisabled()
    }
    const bounds = await pagination.evaluate(element => {
      const rect = element.getBoundingClientRect()
      return { left: rect.left, right: rect.right, width: element.clientWidth, scroll: element.scrollWidth }
    })
    expect(bounds.left).toBeGreaterThanOrEqual(0)
    expect(bounds.right).toBeLessThanOrEqual(width)
    expect(bounds.scroll).toBeLessThanOrEqual(bounds.width + 1)
    await pagination.screenshot({ path: `test-results/pagination-${width}.png` })
  })
}
