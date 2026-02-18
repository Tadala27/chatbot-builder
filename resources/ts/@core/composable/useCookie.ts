// Ported from [Nuxt](https://github.com/nuxt/nuxt/blob/main/packages/nuxt/src/app/composables/cookie.ts)
import { watch, ref, type Ref } from 'vue'
import type { CookieParseOptions, CookieSerializeOptions } from 'cookie-es'
import { parse, serialize } from 'cookie-es'
import { destr } from 'destr'

type _CookieOptions = Omit<CookieSerializeOptions & CookieParseOptions, 'decode' | 'encode'>

export interface CookieOptions<T = any> extends _CookieOptions {
  decode?(value: string): T
  encode?(value: T): string
  default?: () => T | Ref<T>
  watch?: boolean | 'shallow'
}

export type CookieRef<T> = Ref<T>

const CookieDefaults: CookieOptions<any> = {
  path: '/',
  watch: true,
  decode: value => destr(decodeURIComponent(value)),
  encode: value => encodeURIComponent(typeof value === 'string' ? value : JSON.stringify(value)),
}

export const useCookie = <T = string | null | undefined>(name: string, _options?: CookieOptions<T>): CookieRef<T> => {
  const options = { ...CookieDefaults, ..._options || {} }
  const cookies = parse(document.cookie, options)

  const cookie = ref<T | undefined>(cookies[name] as any ?? options.default?.())

  watch(cookie, () => {
    document.cookie = serializeCookie(name, cookie.value, options)
  })

  return cookie as CookieRef<T>
}

function serializeCookie(name: string, value: any, options: CookieSerializeOptions = {}) {
  if (value === null || value === undefined)
    return serialize(name, value, { ...options, maxAge: -1 })

  return serialize(name, value, options)
}
