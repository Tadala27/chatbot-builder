// useIspValue.js
import { ref } from 'vue'

export function useIspValue() {
  const ispValue = ref(false)

  const params = new URLSearchParams(window.location.search)
  const isp = params.get('isp')

  ispValue.value = isp !== null && Number.parseInt(isp) === 1

  return { ispValue }
}
