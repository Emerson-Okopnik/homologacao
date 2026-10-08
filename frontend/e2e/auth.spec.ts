import { expect, test } from '@playwright/test'

const admin = {
  email: process.env.E2E_ADMIN_EMAIL ?? 'admin@demo.homologa.test',
  password: process.env.E2E_ADMIN_PASSWORD ?? 'Homologa@2026',
}

test('redireciona visitante para o login preservando o destino', async ({ page }) => {
  await page.goto('/usuarios')
  await expect(page).toHaveURL(/\/login\?redirect=%2Fusuarios/)
})

test('rejeita credenciais inválidas', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('E-mail').fill(admin.email)
  await page.getByLabel('Senha').fill('senha-errada')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByText(/credenciais|inválid/i)).toBeVisible()
})

test('admin entra, acessa usuários e sai', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('E-mail').fill(admin.email)
  await page.getByLabel('Senha').fill(admin.password)
  await page.getByRole('button', { name: 'Entrar' }).click()

  await expect(page.getByRole('heading', { name: /Olá/ })).toBeVisible()
  await page.getByRole('link', { name: 'Usuários' }).first().click()
  await expect(page.getByRole('heading', { name: 'Usuários' })).toBeVisible()

  await page.getByRole('button', { name: 'Sair' }).click()
  await expect(page).toHaveURL(/\/login/)
})
