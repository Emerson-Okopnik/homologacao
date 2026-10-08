import { ref, shallowRef, watch, type WatchSource } from 'vue'
import { ApiError } from '@/lib/http'

/**
 * Executa `fetcher` sempre que `source` muda, descartando respostas fora de ordem.
 */
export function useApiQuery<T, S>(source: WatchSource<S>, fetcher: (value: S) => Promise<T>) {
  const data = shallowRef<T | null>(null)
  const error = ref<ApiError | null>(null)
  const loading = ref(false)
  let requestId = 0

  async function run(value: S) {
    const current = ++requestId
    loading.value = true
    error.value = null
    try {
      const result = await fetcher(value)
      if (current === requestId) data.value = result
    } catch (e) {
      if (current === requestId) {
        error.value = e instanceof ApiError ? e : new ApiError(0, 'Falha de conexão com o servidor.')
      }
    } finally {
      if (current === requestId) loading.value = false
    }
  }

  const stop = watch(source, (value) => void run(value), { immediate: true, deep: true })

  return { data, error, loading, stop }
}
