import { useAbility } from '@casl/vue'
import type { RouteLocationNormalized } from 'vue-router'

/**
 * Returns ability result if ACL is configured or else just return true
 * We should allow passing string | undefined to can because for admin ability we omit defining action & subject
 *
 * Useful if you don't know if ACL is configured or not
 * Used in @core files to handle absence of ACL without errors
 *
 * @param {string} action CASL Actions // https://casl.js.org/v4/en/guide/intro#basics
 * @param {string} subject CASL Subject // https://casl.js.org/v4/en/guide/intro#basics
 */
export function can(action: string | undefined, subject: string | undefined) {
  const vm = getCurrentInstance()

  if (!vm)
    return false

  const localCan = vm.proxy && '$can' in vm.proxy

  // @ts-expect-error We will get TS error in below line because we aren't using $can in component instance
  return localCan ? vm.proxy?.$can(action, subject) : true
}

/**
 * Check if user can view item based on it's ability
 * Based on item's action and subject & Hide group if all of it's children are hidden
 * @param {object} item navigation object item
 */

export function canNavigate(to: RouteLocationNormalized) {
  const ability = useAbility()

  // @ts-expect-error We should allow passing string | undefined to can because for admin ability we omit defining action & subject
  return to.matched.some(route => ability.can(route.meta.action, route.meta.subject))
}
