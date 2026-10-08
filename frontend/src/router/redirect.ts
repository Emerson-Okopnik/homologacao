/** Evita open redirect: só caminhos internos absolutos são aceitos. */
export function safeRedirect(value: unknown): string {
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : '/'
}
